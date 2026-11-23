<?php
namespace Omps\Modules\System\Controllers;

use Omps\Core\Auth;
use Omps\Core\Controller;
use Omps\Core\Cookie;
use Omps\Core\HttpException;
use Omps\Core\Lang;
use Omps\Core\Request;
use Omps\Core\Response;
use Omps\Core\View;
use Omps\Database\DB;

/**
 * Sign in / out, password change, language choice.
 */
class AuthController extends Controller
{
    public function loginPage(Request $req)
    {
        if ($req->user) {
            return Response::redirect(url('/'));
        }
        $csrf = $req->cookie((string) config('security.csrf_cookie'));
        $res = null;
        if (!preg_match('/^[a-f0-9]{64}$/', $csrf)) {
            $csrf = bin2hex(random_bytes(32));
            $setCookie = Cookie::make((string) config('security.csrf_cookie'), $csrf, null, $req);
        }
        $content = View::render('System::login', array('req' => $req));
        $res = Response::html(View::render('layouts/auth', array('title' => __('auth.sign_in'), 'content' => $content, 'csrf' => $csrf, 'scripts' => array('login'))));
        if (isset($setCookie)) {
            $res->withCookie($setCookie);
        }
        return $res;
    }

    public function login(Request $req)
    {
        $username = $req->str('username');
        $password = $req->input('password', '');
        if ($username === '' || !is_string($password) || $password === '') {
            throw HttpException::bad('auth.enter_credentials');
        }
        $r = Auth::attempt($username, $password, $req);
        $user = $r['user'];
        // a language picked on the sign-in page becomes the user's own choice
        $picked = $req->cookie((string) config('security.lang_cookie'));
        if (($user['lang'] === null || $user['lang'] === '') && $picked !== '' && in_array($picked, Lang::enabledCodes(), true)) {
            DB::table('users')->where('id', $user['id'])->update(array('lang' => $picked));
        }
        $res = Response::json(array('redirect' => $user['must_change_password'] ? url('/password') : url('/')));
        $res->withCookie($r['cookie']);
        return $res;
    }

    public function logout(Request $req)
    {
        $cookie = Auth::logout($req);
        return Response::json(array('redirect' => url('/login')))->withCookie($cookie);
    }

    public function passwordPage(Request $req)
    {
        return $this->renderPage($req, 'System::password', 'auth.change_password', array('forced' => $req->user['must_change_password']), array('password'));
    }

    public function changePassword(Request $req)
    {
        $new = $req->input('new_password', '');
        $confirm = $req->input('confirm_password', '');
        if (!is_string($new) || !is_string($confirm) || $new !== $confirm) {
            throw \Omps\Core\ValidationException::field('confirm_password', 'auth.password_mismatch');
        }
        Auth::changePassword($req, (string) $req->input('old_password', ''), $new);
        return array('redirect' => url('/'));
    }

    /** Language choice: saved on the account when logged in, else in a cookie for the sign-in page. 'auto' = follow the system default. */
    public function setLang(Request $req)
    {
        $code = $req->str('lang');
        $enabled = Lang::enabledCodes();
        if ($code !== 'auto' && !in_array($code, $enabled, true)) {
            throw HttpException::bad('error.bad_request');
        }
        if ($req->user) {
            DB::table('users')->where('id', $req->user['id'])->update(array('lang' => $code === 'auto' ? null : $code));
            return array('lang' => $code);
        }
        $res = Response::json(array('lang' => $code));
        $cookieName = (string) config('security.lang_cookie');
        $res->withCookie($code === 'auto' ? Cookie::forget($cookieName, $req) : Cookie::make($cookieName, $code, 365 * 86400, $req));
        return $res;
    }
}
