<?php namespace crocodicstudio\crudbooster\controllers;

use CRUDBooster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;

class AdminController extends CBController
{
    function getIndex()
    {
        $data = [];
        $data['page_title'] = '<strong>Dashboard</strong>';

        return view('crudbooster::home', $data);
    }

    public function getLockscreen()
    {

        if (! CRUDBooster::myId()) {
            Session::flush();

            return redirect()->route('getLogin')->with('message', cbLang('alert_session_expired'));
        }

        Session::put('admin_lock', 1);

        return view('crudbooster::lockscreen');
    }

    public function postUnlockScreen()
    {
        $id = CRUDBooster::myId();
        $password = request('password');
        $users = DB::table(config('crudbooster.USER_TABLE'))->where('id', $id)->first();

        if (\Hash::check($password, $users->password)) {
            Session::put('admin_lock', 0);

            return redirect(CRUDBooster::adminPath());
        } else {
            echo "<script>alert('".cbLang('alert_password_wrong')."');history.go(-1);</script>";
        }
    }

    public function getLogin()
    {

        if (CRUDBooster::myId()) {
            return redirect(CRUDBooster::adminPath());
        }

        return view('crudbooster::login');
    }

    public function postLogin()
    {
        $recaptcha_response = Request::input('g-recaptcha-response');

        if (is_null($recaptcha_response)) {
            return redirect()->back()->with('message', 'Mohon centang dan selesaikan Captcha!');
        }

        $url = "https://www.google.com/recaptcha/api/siteverify";

        $body = [
            'secret' => config('services.recaptcha.secret'),
            'response' => $recaptcha_response,
            'remoteip' => IpUtils::anonymize(Request::ip()) //anonymize the ip to be GDPR compliant. Otherwise just pass the default ip address
        ];

        $response = Http::asForm()->post($url, $body);
        $result = json_decode($response);

        if (!$response->successful() && $result->success !== true) {
            return redirect()->route('getLogin')->with('message', 'Mohon maaf untuk mencoba lagi centang dan selesaikan Captcha!');
        }

        // ------------------------------------------

        $validator = Validator::make(Request::all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            $message = $validator->errors()->all();

            return redirect()->back()->with(['message' => implode(', ', $message), 'message_type' => 'danger']);
        }

        $username = Request::input("username");
        $password = Request::input("password");
        // $users = DB::table(config('crudbooster.USER_TABLE'))->where("email", $email)->first();


        if ($username=="superadmin" || $username=="pemeriksa" || $username=="inspektur" || $username=="inspektoratdps") {
            $username = Request::input("username");
            $password = Request::input("password");
            $users = DB::table(config('crudbooster.USER_TABLE'))->where("username", $username)->first();

            if (\Hash::check($password, $users->password)) {
                $priv = DB::table("cms_privileges")->where("id", $users->id_cms_privileges)->first();
    
                $roles = DB::table('cms_privileges_roles')->where('id_cms_privileges', $users->id_cms_privileges)->join('cms_moduls', 'cms_moduls.id', '=', 'id_cms_moduls')->select('cms_moduls.name', 'cms_moduls.path', 'is_visible', 'is_create', 'is_read', 'is_edit', 'is_delete')->get();
    
                $photo = ($users->photo) ? asset($users->photo) : asset('vendor/crudbooster/avatar.jpg');
                Session::put('admin_id', $users->id);
                Session::put('admin_is_superadmin', $priv->is_superadmin);
                Session::put('admin_name', $users->name);
                Session::put('admin_photo', $photo);
                Session::put('admin_privileges_roles', $roles);
                Session::put("admin_privileges", $users->id_cms_privileges);
                Session::put('admin_privileges_name', $priv->name);
                Session::put('admin_lock', 0);
                Session::put('theme_color', $priv->theme_color);
                Session::put("appname", get_setting('appname'));
    
                CRUDBooster::insertLog(cbLang("log_login", ['username' => $users->username, 'ip' => Request::server('REMOTE_ADDR')]));
    
                $cb_hook_session = new \App\Http\Controllers\CBHook;
                $cb_hook_session->afterLogin();
    
                return redirect(CRUDBooster::adminPath());
            } else {
                return redirect()->route('getLogin')->with('message', cbLang('alert_password_wrong'));
            }

        } else {
            // POST method
            // $response = Http::asForm()->post('https://splp.denpasarkota.go.id/dev/simpeg/sso/', [
                // $user is the GenericUser instance created in
                // the retrieveByCredentials() method above.
                // 'username' => $username,
                // 'password' => $password,
            // ]);

            // get tiket
            $response = Http::get('https://simpeg.denpasarkota.go.id/index.php?user=servicelogin&username='.$username.'&pass='.$password);
            $rp = array();
            $rp['tiket'] = $response['tiket'];

            // autentikasi
            $response = Http::get('https://simpeg.denpasarkota.go.id/index.php?page=sso&tiket='.$rp['tiket']);
            $rp['status'] = $response['status'];

            // dd($response->json());

            if ($rp['status']==='success') {
                $users = $response->json();
    
                if($users['usergroup_id']==18) {
                    $hak_akses = 2;
                    $super_admin = 0;
                // } else if($users['usergroup']=='04') {
                //     // $hak_akses = 1;
                //     // $super_admin = 1;
                //     $hak_akses = 2;
                //     $super_admin = 0;
                } else {
                    return redirect()->route('getLogin')->with('message', 'Hak Akses Tidak Ada!');
                    exit();
                }
                $priv = DB::table("cms_privileges")->where("id", $hak_akses)->first();
                $roles = DB::table('cms_privileges_roles')->where('id_cms_privileges', $hak_akses)->join('cms_moduls', 'cms_moduls.id', '=', 'id_cms_moduls')->select('cms_moduls.name', 'cms_moduls.path', 'is_visible', 'is_create', 'is_read', 'is_edit', 'is_delete')->get();
    
                $photo = ($users['photo']) ? asset($users['photo']) : asset('vendor/crudbooster/avatar.jpg');

                // get Data Pegawai
                $response = Http::get('https://simpeg.denpasarkota.go.id/index.php?page=sso&tiket='.$rp['tiket'].'&action=getDataPegawai');
                $user = $response['result']['pegawai'];
                $user_jab = $response['result']['jabatan'][0];
                
                // dd($response->json());
    
                Session::put('admin_id', $user['peg_nip']);

                Session::put('admin_peg_id', $user['peg_nip']);
                Session::put('admin_passcode', null);
                Session::put('admin_opd', $user_jab['unit_name']);
                Session::put('admin_opd_jab', $user_jab['jab_name']);
                Session::put('admin_ssoid', $rp['tiket']);

                Session::put('admin_is_superadmin', $super_admin);
                Session::put('admin_name', $user['peg_nama']);
                Session::put('admin_photo', $user['url_photo']);
                Session::put('admin_privileges_roles', $roles);
                Session::put("admin_privileges", $hak_akses);
                Session::put('admin_privileges_name', $priv->name);
                Session::put('admin_lock', 0);
                Session::put('theme_color', $priv->theme_color);
                Session::put("appname", get_setting('appname'));
    
                CRUDBooster::insertLog(cbLang("log_login", ['email' => $response['content']['name'], 'ip' => Request::server('REMOTE_ADDR')]));
    
                $cb_hook_session = new \App\Http\Controllers\CBHook;
                $cb_hook_session->afterLogin();
    
                return redirect(CRUDBooster::adminPath());
            } else {
                return redirect()->route('getLogin')->with('message', 'Identitas tidak benar, mohon cek informasi login Anda!');
            }
        }

        // return $response->json();
        // dd($response['content']);

        
    }

    public function getForgot()
    {
        if (CRUDBooster::myId()) {
            return redirect(CRUDBooster::adminPath());
        }

        return view('crudbooster::forgot');
    }

    public function postForgot()
    {
        $validator = Validator::make(Request::all(), [
            'email' => 'required|email|exists:'.config('crudbooster.USER_TABLE'),
        ]);

        if ($validator->fails()) {
            $message = $validator->errors()->all();

            return redirect()->back()->with(['message' => implode(', ', $message), 'message_type' => 'danger']);
        }

        $rand_string = str_random(5);
        $password = \Hash::make($rand_string);

        DB::table(config('crudbooster.USER_TABLE'))->where('email', Request::input('email'))->update(['password' => $password]);

        $appname = CRUDBooster::getSetting('appname');
        $user = CRUDBooster::first(config('crudbooster.USER_TABLE'), ['email' => g('email')]);
        $user->password = $rand_string;
        CRUDBooster::sendEmail(['to' => $user->email, 'data' => $user, 'template' => 'forgot_password_backend']);

        CRUDBooster::insertLog(cbLang("log_forgot", ['email' => g('email'), 'ip' => Request::server('REMOTE_ADDR')]));

        return redirect()->route('getLogin')->with('message', cbLang("message_forgot_password"));
    }

    public function getLogout()
    {

        // $me = CRUDBooster::me();
        $name = Session::get('admin_name');

        CRUDBooster::insertLog(cbLang("log_logout", ['email' => $name]));

        Session::flush();

        return redirect()->route('getLogin')->with('message', cbLang("message_after_logout"));
    }
}