<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Role;
use App\Repositories\Fees\FeesInterface;
use App\Repositories\Notification\NotificationInterface;
use App\Repositories\User\UserInterface;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\Validator;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class NotificationController extends Controller
{

    private NotificationInterface $notification;
    private CachingService $cache;
    private UserInterface $user;
    private FeesInterface $fees;

    public function __construct(NotificationInterface $notification, CachingService $cache, UserInterface $user, FeesInterface $fees)
    {
        $this->notification = $notification;
        $this->cache = $cache;
        $this->user = $user;
        $this->fees = $fees;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        ResponseService::noFeatureThenRedirect('Announcement Management');
        ResponseService::noAnyPermissionThenRedirect(['notification-create', 'notification-list']);

        $roles = Role::whereNot('name', 'Guardian')->pluck('name');
        $users = $this->user->guardian()->with('roles')->whereHas('child.user', function ($q) {
            $q->owner();
        })->orWhere(function ($q) use ($roles) {
            $q->where('school_id', Auth::user()->school_id)
                ->whereHas('roles', function ($q) use ($roles) {
                    $q->whereIn('name', $roles);
                });
        })->get();

        $all_users = $users->pluck('id')->toArray();
        $all_users = implode(",", $all_users);

        return view('notification.index', compact('users', 'roles', 'all_users'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
        ResponseService::noFeatureThenRedirect('Announcement Management');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'message' => 'required',
            'type' => 'required',
            'roles' => 'required_if:type,Roles|array',
            'user' => 'required_if:type,Specific users|array', 
        ], [
            'title.required' => 'Title is required.',
            'message.required' => 'Message is required.',
            'type.required' => 'Notification type is required.',
            'roles.required_if' => 'Please select roles if the notification type is "Roles".',
            'user.required_if' => 'Please select users if the notification type is "Specific users".',
        ]);

        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $data = [
                'title' => $request->title,
                'message' => $request->message,
                'send_to' => $request->type ?? '',
                'image' => $request->hasFile('image') ? $request->image : null,
                'session_year_id' => $sessionYear->id
            ];
            $notification = $this->notification->create($data);
            $notifyUser = [];

            if ($request->type == 'All users') {
                if ($request->has('all_users')) {
                    $notifyUser = explode(",", $request->all_users);
                }
            } else if ($request->type == 'Specific users') {
                if ($request->has('user') && is_array($request->user)) {
                    $notifyUser = $request->user;
                }
            } else if ($request->type == 'Over Due Fees') {
                $today = Carbon::now()->format('Y-m-d');
                $student_ids = [];
                $guardian_ids = [];

                $fees = $this->fees->builder()->whereDate('due_date', '<', $today)->get();
                if ($fees->isNotEmpty()) {
                    foreach ($fees as $fee) {
                        $sql = $this->user->builder()->role('Student')->select('id', 'first_name', 'last_name')->with([
                            'fees_paid'  => function ($q) use ($fee) {
                                $q->where('fees_id', $fee->id);
                            },
                            'student:id,guardian_id,user_id', 'student.guardian:id'
                        ])->whereHas('student.class_section', function ($q) use ($fee) {
                            $q->where('class_id', $fee->class_id);
                        })->whereDoesntHave('fees_paid', function ($q) use ($fee) {
                            $q->where('fees_id', $fee->id);
                        })->orWhereHas('fees_paid', function ($q) use ($fee) {
                            $q->where(['fees_id' => $fee->id, 'is_fully_paid' => 0]);
                        });
                        $student_ids[] = $sql->pluck('id')->toArray();
                        $guardian_ids[] = $sql->get()->pluck('student.guardian_id')->toArray();
                    }
                }

                // Merge the arrays properly
                $student_ids = array_merge(...$student_ids);
                $guardian_ids = array_merge(...$guardian_ids);
                $notifyUser = array_merge($student_ids, $guardian_ids);
            } else if ($request->type == 'Roles') {
                $guardian_ids = [];
                if (!empty($request->roles) && in_array('Guardian', $request->roles)) {
                    $guardian_ids = $this->user->guardian()->with('roles')->whereHas('child.user', function ($q) {
                        $q->owner();
                    })->pluck('id')->toArray();
                    $roles = array_diff($request->roles, ["Guardian"]);
                    $notifyUser = $this->user->builder()->role($roles)->pluck('id')->toArray();
                } else {
                    $notifyUser = $this->user->builder()->role($request->roles)->pluck('id')->toArray();
                }
                $notifyUser = array_merge($guardian_ids, $notifyUser);
            }

            $customData = [];
            if ($notification->image) {
                $customData = [
                    'image' => $notification->image
                ];
            }
            $title = $request->title; // Title for Notification
            $body = $request->message;
            $type = 'Notification';

            DB::commit();
            send_notification($notifyUser, $title, $body, $type, $customData); // Send Notification
            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            if (Str::contains($e->getMessage(), [
                'does not exist','file_get_contents'
            ])) {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.");
            } else {
                DB::rollBack();
                ResponseService::logErrorResponse($e, "Notification Controller -> Store Method");
                ResponseService::errorResponse();
            }
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        //
        ResponseService::noFeatureThenRedirect('Announcement Management');
        ResponseService::noPermissionThenRedirect('notification-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');

        $sql = $this->notification->builder()
            ->where(function ($query) use ($search) {
                $query->when($search, function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%$search%")->orwhere('message', 'LIKE', "%$search%")->Owner();
                });
            });
        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            $operate = BootstrapTableService::deleteButton(route('notifications.destroy', $row->id));
            $tempRow = $row->toArray();
            $tempRow['send_to'] = trans($row->send_to);
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        //
        ResponseService::noFeatureThenRedirect('Announcement Management');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        //
        ResponseService::noFeatureThenRedirect('Announcement Management');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
        ResponseService::noFeatureThenRedirect('Announcement Management');
        ResponseService::noPermissionThenRedirect('notification-delete');
        try {
            $this->notification->deleteById($id);
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Notification Controller -> Delete Method");
            ResponseService::errorResponse();
        }
    }

    public function userShow(Request $request)
    {

        ResponseService::noFeatureThenRedirect('Announcement Management');
        ResponseService::noPermissionThenRedirect('notification-create');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');

        $sql = $this->user->builder()->whereHas('roles', function($q) {
            $q->whereNot('name','School Admin');
        })
            ->where(function ($query) use ($search) {
                $query->when($search, function ($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%$search%")
                    ->orwhere('last_name', 'LIKE', "%$search%")
                    ->orWhereRaw("concat(first_name,' ',last_name) LIKE '%" . $search . "%'")
                    ->Owner();
                });
            });

        if ($request->roles) {
            $sql = $sql->whereHas('roles', function($q) use($request) {
                $q->whereIn('name',$request->roles);
            });
        }
        
        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;            
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);

    }
}
