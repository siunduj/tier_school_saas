<?php

namespace App\Http\Controllers;

use App\Repositories\SystemSetting\SystemSettingInterface;
use App\Repositories\User\UserInterface;
use App\Services\CachingService;
use App\Services\ResponseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Throwable;

class AuthController extends Controller {
    private UserInterface $user;
    private CachingService $cache;
    private SystemSettingInterface $systemSettings;

    public function __construct(UserInterface $user, CachingService $cachingService, SystemSettingInterface $systemSettings) {
        // $this->middleware('auth');
        $this->user = $user;
        $this->cache = $cachingService;
        $this->systemSettings = $systemSettings;
    }

    public function login() {
        if (Auth::user()) {
            return redirect('/');
        }
        $systemSettings = $this->cache->getSystemSettings();
        return view('auth.login', compact('systemSettings'));
    }


    public function changePasswordIndex() {
        return view('auth.change-password');
    }

    public function changePasswordStore(request $request) {
        if (env('DEMO_MODE')) {
            return response()->json(array(
                'error'   => true,
                'message' => "This is not allowed in the Demo Version.",
                'code'    => 112
            ));
        }
        $id = Auth::id();
        $request->validate([
            'old_password'     => 'required',
            'new_password'     => 'required|min:8',
            'confirm_password' => 'required|same:new_password',
        ]);
        try {
            if (Hash::check($request->old_password, Auth::user()->password)) {
                $data['password'] = Hash::make($request->new_password);
                $this->user->builder()->where('id', $id)->update($data);
                $response = array(
                    'error'   => false,
                    'message' => trans('Data Updated Successfully')
                );
            } else {
                ResponseService::errorResponse('In valid old password');
            }


        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "HomeController --> Change Password Method");
            ResponseService::errorResponse();
        }
        return response()->json($response);
    }

    public function checkPassword(Request $request) {
        $old_password = $request->old_password;
        $password = $this->user->findById(Auth::id());
        if (Hash::check($old_password, $password->password)) {
            return response()->json(1);
        }

        return response()->json(0);
    }


    public function logout(Request $request) {
        session(['logout_time' => now()]);
        $user = Auth::user();
        DB::table('users')->where('email',$user->email)->update(['two_factor_secret' => null,'two_factor_expires_at' => null]);
        Auth::logout();
        $request->session()->flush();
        $request->session()->regenerate();
        session()->forget('school_database_name');
        Session::forget('school_database_name');
        return redirect('/');
    }

    public function profileEdit() {
        $userData = Auth::user();
        return view('auth.profile', compact('userData'));
    }

    public function profileUpdate(Request $request) {
        if (env('DEMO_MODE')) {
            return response()->json(array(
                'error'   => true,
                'message' => "This is not allowed in the Demo Version.",
                'code'    => 112
            ));
        }
        $request->validate([
            'first_name' => 'required',
            'last_name'  => 'required',
            'mobile'     => 'nullable|numeric|digits_between:1,16',
            'gender'     => 'required',
            'dob'        => 'required',
            'email'      => 'required|email|unique:users,email,' . Auth::user()->id,

            'current_address'   => 'required',
            'permanent_address' => 'required',
        ]);
        try {
            $userData = array(
                ...$request->all()
            );
            if (!empty($request->image)) {
                $userData['image'] = $request->image;
            }
            $this->user->update(Auth::user()->id, $userData);

            if (Auth::user()->hasRole('Super Admin')) {
                $data[] = [
                    'name' => 'super_admin_name',
                    'data' => $request->first_name .' '. $request->last_name,
                    'type' => 'string'
                ];
                $this->systemSettings->upsert($data,['name'],['data','type']);
                $this->cache->removeSystemCache(config('constants.CACHE.SYSTEM.SETTINGS'));
            }

            if (Auth::user()->hasRole('School Admin')) {
                $id = Auth::user()->id;
                DB::setDefaultConnection('mysql');
                $this->user->update($id, $userData);
            }
            

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Home Controller -> updateProfile Method");
            ResponseService::errorResponse();
        }
    }

    public function twoFactorAuthentication() {
        return view('auth.2fa');
    }
    
    public function twoFactorAuthenticationCode(Request $request) {
        
        // Maximum allowed failed attempts
        $maxFailedAttempts = 3;
        $failedAttempts = (int) $request->cookie('failed_attempts', 0);
        $user = Auth::user();

        if($user->two_factor_secret == $request->two_factor_secret) {

            Cookie::queue(Cookie::forget('failed_attempts'));
            Cookie::queue(Cookie::forget('last_failed_attempt'));

            DB::table('users')->where('email', $user->email)->update(['two_factor_expires_at' => Carbon::now()->addDays(1)]);

            return redirect()->intended('/dashboard');
        } else {
            
            if ($failedAttempts >= $maxFailedAttempts) {
                return back()->withErrors(['code' => 'Last one attempt. Please try again.']);
            } 

            $failedAttempts++;
            Cookie::queue(Cookie::forever('failed_attempts', $failedAttempts));
            
            if ($failedAttempts >= $maxFailedAttempts) {
                Cookie::queue(Cookie::forget('failed_attempts'));
                
                DB::table('users')->where('email', $user->email)->update(['two_factor_secret' => null, 'two_factor_expires_at' => null]);
                Auth::logout();
                $request->session()->flush();
                $request->session()->regenerate();
                session()->forget('school_database_name');
                Session::forget('school_database_name');
                
                return redirect('/');
            }

            return back()->withErrors(['code' => 'Invalid code. Please try again.']);
        }

    }

    
}
