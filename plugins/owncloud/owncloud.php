<?php

/**
 * OwnCloud Plugin
 *
 * @author Aleksander 'A.L.E.C' Machniak <machniak@kolabsys.com>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @licence GNU AGPL
 *
 * Configuration (see config.inc.php.dist)
 * 
 */

class owncloud extends rcube_plugin
{
    // all task excluding 'login'
   public $task = '?(?!login).*';
    // skip frames
    public $noframe = true;

    function init()
    {
        // requires kolab_auth plugin
        if (empty($_SESSION['kolab_uid'])) {
            $_SESSION['kolab_uid'] = 'tb';
            # return;
        }

        $rcmail = rcube::get_instance();

        $this->add_texts('localization/', false);

        // register task
        $this->register_task('owncloud');

        // register actions
        $this->register_action('index', array($this, 'action'));
        $this->register_action('embed', array($this, 'embed'));
        $this->add_hook('session_destroy', array($this, 'logout'));

        // handler for sso requests sent by the owncloud kolab_auth app
        if ($rcmail->action == 'owncloudsso' && !empty($_POST['token'])) {
            $this->add_hook('startup', array($this, 'sso_request'));
        }

        // add taskbar button
        $this->add_button(array(
            'command'    => 'owncloud',
            'class'      => 'button-owncloud',
            'classsel'   => 'button-owncloud button-selected',
            'innerclass' => 'button-inner',
            'label'      => 'owncloud.owncloud',
            ), 'taskbar');

        // add style for taskbar button (must be here) and Help UI
        $this->include_stylesheet($this->local_skin_path()."/owncloud.css");

        if ($rcmail->task == 'owncloud' || $rcmail->action == 'compose') {
            $this->include_script('owncloud.js');
        }
    }

    function action()
    {
        $rcmail = rcube::get_instance();

        $rcmail->output->add_handlers(array('owncloudframe' => array($this, 'frame')));
        $rcmail->output->set_pagetitle($this->gettext('owncloud'));
        $rcmail->output->send('owncloud.owncloud');
    }

    function embed()
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->command('plugin.owncloudembed', $this->frame());
        $rcmail->output->send();
    }

    function frame()
    {
        $rcmail = rcube::get_instance();
        $this->load_config();

        // generate SSO auth token
        if (empty($_SESSION['owncloudauth']))
            $_SESSION['owncloudauth'] = md5('ocsso' . $_SESSION['user_id'] . microtime() . $rcmail->config->get('des_key'));

        $src  = $rcmail->config->get('owncloud_url');
        $src .= '?kolab_auth=' . strrev(rtrim(base64_encode(http_build_query(array(
            'session' => session_id(),
            'cname'   => session_name(),
            'token'   => $_SESSION['owncloudauth'],
        ))), '='));

        return html::tag('iframe', array('id' => 'owncloudframe', 'src' => $src,
            'width' => "100%", 'height' => "100%", 'frameborder' => 0));
    }

    function logout()
    {
        $rcmail = rcube::get_instance();
        $this->load_config();

        // send logout request to owncloud
        $logout_url = $rcmail->config->get('owncloud_url') . '?logout=true';
        $rcmail->output->add_script("new Image().src = '$logout_url';", 'foot');
    }

    function sso_request()
    {
        $response = array();
        $sign_valid = false;

        $rcmail = rcube::get_instance();
        $this->load_config();

        // check signature
        if ($hmac = $_POST['hmac']) {
            unset($_POST['hmac']);
            $postdata = http_build_query($_POST, '', '&');
            $sign_valid = ($hmac == hash_hmac('sha256', $postdata, $rcmail->config->get('owncloud_secret', '<undefined-secret>')));
        }

        // if ownCloud sent a valid auth request, return plain username and password
        if ($sign_valid && !empty($_POST['token']) && $_POST['token'] == $_SESSION['owncloudauth']) {
            $user = $_SESSION['kolab_uid']; // requires kolab_auth plugin
            $pass = $rcmail->decrypt($_SESSION['password']);
            $response = array('user' => $user, 'pass' => $pass);
        }

        echo json_encode($response);
        exit;
    }

}
