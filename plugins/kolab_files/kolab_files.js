/**
 * Kolab files plugin
 *
 * @version @package_version@
 * @author Aleksander Machniak <alec@alec.pl>
 */

window.rcmail && rcmail.addEventListener('init', function() {
  if (rcmail.env.task == 'mail') {
    // mail compose
    if (rcmail.env.action == 'compose') {
      var elem = $('#compose-attachments > div'),
        input = $('<input class="button" type="button">');

        input.val(rcmail.gettext('kolab_files.fromcloud'))
          .click(function() { kolab_files_selector_dialog(); })
          .appendTo(elem);

        var dialog = $('<div id="files-compose-dialog"></div>').hide();
        $('body').append(dialog);
    }
    // mail preview
    else if (rcmail.env.action == 'show' || rcmail.env.action == 'preview') {
      var attachment_list = $('#attachment-list');

      if ($('li', attachment_list).length) {
        var link = $('<a href="#">')
          .text(rcmail.gettext('kolab_files.saveall'))
          .click(function() { kolab_directory_selector_dialog(); })
          .appendTo(attachment_list);

        var dialog = $('<div id="files-dialog"></div>').hide();
        $('body').append(dialog);
      }
    }

    kolab_files_init();
  }
});

function kolab_files_init()
{
  if (window.file_api)
    return;

  // Initialize application object (don't change var name!)
  file_api = $.extend(new files_api(), new kolab_files_ui());

  file_api.set_env({
    token: kolab_files_token(),
    url: rcmail.env.files_url,
    sort_column: 'name',
    sort_reverse: 0
  });
};

function kolab_directory_selector_dialog()
{
  var dialog = $('#files-dialog'), buttons = {};

  buttons[rcmail.gettext('kolab_files.save')] = function () {
    var lock = rcmail.set_busy(true, 'saving');
    rcmail.http_post('plugin.kolab_files', {
      act: 'saveall',
      source: rcmail.env.mailbox,
      uid: rcmail.env.uid,
      dest: file_api.env.folder
    }, lock);
    $('#files-dialog').dialog('destroy').hide();
  };
  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    $('#files-dialog').dialog('destroy').hide();
  };

  // show dialog window
  dialog.dialog({
    modal: false,
    resizable: !bw.ie6,
    closeOnEscape: (!bw.ie6 && !bw.ie7),  // disable for performance reasons
    title: rcmail.gettext('kolab_files.saveall'),
//    close: function() { rcmail.dialog_close(); },
    buttons: buttons,
    minWidth: 300,
    minHeight: 300,
    height: 250,
    width: 250
    }).html('<div id="files-folder-selector"></div>').show();

  file_api.folder_selector();
};

function kolab_files_selector_dialog()
{
  var dialog = $('#files-compose-dialog'), buttons = {};

  buttons[rcmail.gettext('kolab_files.attachsel')] = function () {
    var list = [];
    $('#filelist tr.selected').each(function() {
      list.push($(this).data('file'));
    });

    $('#files-compose-dialog').dialog('destroy').hide();

    if (list.length) {
      // display upload indicator and cancel button
      var content = '<span>' + rcmail.get_label('uploading' + (list.length > 1 ? 'many' : '')) + '</span>',
        id = new Date().getTime();

      rcmail.add2attachment_list(id, { name:'', html:content, classname:'uploading', complete:false });

      // send request
      rcmail.http_post('plugin.kolab_files', {
        act: 'attach',
        folder: file_api.env.folder,
        files: list,
        id: rcmail.env.compose_id,
        uploadid: id
      });
    }
  };
  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    $('#files-compose-dialog').dialog('destroy').hide();
  };

  // show dialog window
  dialog.dialog({
    modal: false,
    resizable: !bw.ie6,
    closeOnEscape: (!bw.ie6 && !bw.ie7),  // disable for performance reasons
    title: rcmail.gettext('kolab_files.selectfiles'),
//    close: function() { rcmail.dialog_close(); },
    buttons: buttons,
    minWidth: 400,
    minHeight: 300,
    width: 600,
    height: 400
    }).html('<div id="files-folder-selector"></div><div id="files-file-selector"><table id="filelist"><tbody></tbody></table></div>').show();

  file_api.folder_selector();
};

function kolab_files_token()
{
  // consider the token from parent window more reliable (fresher) than in framed window
  // it's because keep-alive is not requested in frames
  return (window.parent && parent.rcmail && parent.rcmail.env.files_token) || rcmail.env.files_token;
};

function kolab_files_ui()
{
/*
  // Called on "session expired" session
  this.logout = function(response) {};

  // called when a request timed out
  this.request_timed_out = function() {};

  // called on start of the request
  this.set_request_time = function() {};

  // called on request response
  this.update_request_time = function() {};
*/
  // set state
  this.set_busy = function(a, message)
  {
    if (this.req)
      rcmail.hide_message(this.req);

    return rcmail.set_busy(a, message);
  };

  // displays error message
  this.display_message = function(label)
  {
    return rcmail.display_message(this.t(label));
  };

  this.http_error = function(request, status, err)
  {
    rcmail.http_error(request, status, err);
  };

  this.folder_selector = function()
  {
    this.req = this.set_busy(true, 'loading');
    this.get('folder_list', {}, 'folder_selector_response');
  };

  // folder list response handler
  this.folder_selector_response = function(response)
  {
    if (!this.response(response))
      return;

    var first, elem = $('#files-folder-selector'),
      table = $('<table>');

    elem.html('').append(table);

    this.env.folders = this.folder_list_parse(response.result);

    table.empty();

    $.each(this.env.folders, function(i, f) {
      var row = $('<tr><td><span class="branch"></span><span class="name"></span></td></tr>'),
        span = $('span.name', row);

      span.text(f.name);
      row.attr('id', f.id);

      if (f.depth)
        $('span.branch', row).width(15 * f.depth);

      if (f.virtual)
        row.addClass('virtual');
      else
       span.click(function() { file_api.selector_select(i); });

//      if (i == file_api.env.folder)
//        row.addClass('selected');

      table.append(row);

      if (!first)
        first = i;
    });

   // select first folder
   if (first)
     this.selector_select(first);

    // add tree icons
    this.folder_list_tree(this.env.folders);
  };

  this.selector_select = function(i)
  {
    var list = $('#files-folder-selector > table');
    $('tr.selected', list).removeClass('selected');
    $('#' + this.env.folders[i].id, list).addClass('selected');

    this.env.folder = i;

    // list files in selected folder
    if (rcmail.env.action == 'compose') {
      this.file_selector();
    }
  };

  this.file_selector = function(params)
  {
    if (!params)
      params = {};

    params.folder = this.env.folder;

    if (params.sort == undefined)
      params.sort = this.env.sort_col;
    if (params.reverse == undefined)
      params.reverse = this.env.sort_reverse;
    if (params.search == undefined)
      params.search = this.env.search;

    this.env.sort_col = params.sort;
    this.env.sort_reverse = params.reverse;

    this.req = this.set_busy(true, 'loading');
    this.get('file_list', params, 'file_selector_response');
  };

  // file list response handler
  this.file_selector_response = function(response)
  {
    if (!this.response(response))
      return;

    var table = $('#filelist');

    $('tbody', table).empty();

    $.each(response.result, function(key, data) {
      var row = $('<tr><td class="filename"></td>'
          + /* '<td class="filemtime"></td>' */ '<td class="filesize"></td></tr>'),
        link = $('<span></span>').text(key);

      $('td.filename', row).addClass(file_api.file_type_class(data.type)).append(link);
//      $('td.filemtime', row).text(data.mtime);
      $('td.filesize', row).text(file_api.file_size(data.size));
      row.attr('data-file', urlencode(key))
        .click(function(e) { file_api.file_select(e, this); });

      table.append(row);
    });
  };

  this.file_select = function(e, row)
  {
    var table = $('#filelist');
//    $('tr.selected', table).removeClass('selected');
    $(row).addClass('selected');
  };
};
