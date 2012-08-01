<?php

/**
 * Open Document Viewer plugin
 *
 * Render Open Documents directly in the preview window
 * by using the WebODF library by Tobias Hintze http://webodf.org/
 *
 * @version 0.2
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
class odfviewer extends rcube_plugin
{
  public $task = 'mail|calendar|tasks|logout';
  
  private $tempdir  = 'plugins/odfviewer/files/';
  private $tempbase = 'plugins/odfviewer/files/';
  
  private $odf_mimetypes = array(
    'application/vnd.oasis.opendocument.chart',
    'application/vnd.oasis.opendocument.chart-template',
    'application/vnd.oasis.opendocument.formula',
    'application/vnd.oasis.opendocument.formula-template',
    'application/vnd.oasis.opendocument.graphics',
    'application/vnd.oasis.opendocument.graphics-template',
    'application/vnd.oasis.opendocument.presentation',
    'application/vnd.oasis.opendocument.presentation-template',
    'application/vnd.oasis.opendocument.text',
    'application/vnd.oasis.opendocument.text-master',
    'application/vnd.oasis.opendocument.text-template',
    'application/vnd.oasis.opendocument.spreadsheet',
    'application/vnd.oasis.opendocument.spreadsheet-template',
  );

  function init()
  {
    $this->tempdir = $this->home . '/files/';
    $this->tempbase = $this->urlbase . 'files/';

    // webODF only supports IE9 or higher
    $ua = new rcube_browser;
    if ($ua->ie && $ua->ver < 9)
      return;
    // extend list of mimetypes that should open in preview
    $rcmail = rcmail::get_instance();
    if ($rcmail->action == 'preview' || $rcmail->action == 'show' || $rcmail->task == 'calendar' || $rcmail->task == 'tasks') {
      $mimetypes = $rcmail->config->get('client_mimetypes', 'text/plain,text/html,text/xml,image/jpeg,image/gif,image/png,application/x-javascript,application/pdf,application/x-shockwave-flash');
      if (!is_array($mimetypes))
        $mimetypes = explode(',', $mimetypes);
      $rcmail->config->set('client_mimetypes', array_merge($mimetypes, $this->odf_mimetypes));
    }

    $this->add_hook('message_part_get', array($this, 'get_part'));
    $this->add_hook('session_destroy', array($this, 'session_cleanup'));
  }

  /**
   * Handler for message attachment download
   */
  function get_part($args)
  {
    if (!$args['download'] && $args['mimetype'] && in_array($args['mimetype'], $this->odf_mimetypes)) {
      if (empty($_GET['_load'])) {
        $suffix = preg_match('/(\.\w+)$/', $args['part']->filename, $m) ? $m[1] : '.odt';
        $fn = md5(session_id() . $_SERVER['REQUEST_URI']) . $suffix;

        // FIXME: copy file to disk because only apache can send the file correctly
        $tempfn = $this->tempdir . $fn;
        if (!file_exists($tempfn)) {
          if ($args['body']) {
            file_put_contents($tempfn, $args['body']);
          }
          else {
            $fp = fopen($tempfn, 'w');
            $imap = rcmail::get_instance()->get_storage();
            $imap->get_message_part($args['uid'], $args['id'], $args['part'], false, $fp);
            fclose($fp);
          }

          // remember tempfiles in session to clean up on logout
          $_SESSION['odfviewer']['tempfiles'][] = $fn;
        }
        
        // send webODF viewer page
        $html = file_get_contents($this->home . '/odf.html');
        header("Content-Type: text/html; charset=" . RCMAIL_CHARSET);
        echo strtr($html, array(
          '%%DOCROOT%%' => $this->urlbase,
          '%%DOCURL%%' => $this->tempbase . $fn, # $_SERVER['REQUEST_URI'].'&_load=1',
          ));
        $args['abort'] = true;
      }
/*
      else {
        if ($_SERVER['REQUEST_METHOD'] == 'HEAD') {
          header("Content-Length: " . max(10, $args['part']->size));  # content-length has to be present
          $args['body'] = ' ';  # send empty body
          return $args;
        }
      }
*/
    }

    return $args;
  }

  /**
   * Remove temp files opened during this session
   */
  function session_cleanup()
  {
    foreach ((array)$_SESSION['odfviewer']['tempfiles'] as $fn) {
      @unlink($this->tempdir . $fn);
    }
    
    // also trigger general garbage collection because not everybody logs out properly
    $this->gc_cleanup();
  }

  /**
   * Garbage collector function for temp files.
   * Remove temp files older than two days
   */
  function gc_cleanup()
  {
    $rcmail = rcmail::get_instance();

    $tmp = unslashify($this->tempdir);
    $expire = mktime() - 172800;  // expire in 48 hours

    if ($dir = opendir($tmp)) {
      while (($fname = readdir($dir)) !== false) {
        if ($fname[0] == '.')
          continue;

        if (filemtime($tmp.'/'.$fname) < $expire)
          @unlink($tmp.'/'.$fname);
      }

      closedir($dir);
    }
  }
}

