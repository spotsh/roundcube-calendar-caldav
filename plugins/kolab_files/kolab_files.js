/**
 * Kolab files plugin
 *
 * @version @package_version@
 * @author Aleksander Machniak <alec@alec.pl>
 */

window.rcmail && rcmail.addEventListener('init', function() {
  if (rcmail.task == 'mail') {
    // mail compose
    if (rcmail.env.action == 'compose') {
      var elem = $('#compose-attachments > div'),
        input = $('<input class="button" type="button">');

      input.val(rcmail.gettext('kolab_files.fromcloud'))
        .click(function() { kolab_files_selector_dialog(); })
        .appendTo(elem);

      if (rcmail.gui_objects.filelist) {
        rcmail.file_list = new rcube_list_widget(rcmail.gui_objects.filelist, {
          multiselect: true,
//        draggable: true,
          keyboard: true,
          column_movable: false,
          dblclick_time: rcmail.dblclick_time
        });
        rcmail.file_list.addEventListener('select', function(o) { kolab_files_list_select(o); });
        rcmail.file_list.addEventListener('listupdate', function(e) { rcmail.triggerEvent('listupdate', e); });

        rcmail.gui_objects.filelist.parentNode.onmousedown = function(e){ return kolab_files_click_on_list(e); };
        rcmail.enable_command('files-sort', 'files-search', 'files-search-reset', true);

        rcmail.file_list.init();
        kolab_files_list_coltypes();
      }
    }
    // mail preview
    else if (rcmail.env.action == 'show' || rcmail.env.action == 'preview') {
      var attachment_list = $('#attachment-list');

      if ($('li', attachment_list).length) {
        var link = $('<a href="#" class="button filesaveall">')
          .text(rcmail.gettext('kolab_files.saveall'))
          .click(function() { kolab_directory_selector_dialog(); })
          .appendTo(attachment_list);
      }
    }

    kolab_files_init();
  }
  else if (rcmail.task == 'files') {
    if (rcmail.gui_objects.filelist) {
      rcmail.file_list = new rcube_list_widget(rcmail.gui_objects.filelist, {
        multiselect: true,
        draggable: true,
        keyboard: true,
        column_movable: rcmail.env.col_movable,
        dblclick_time: rcmail.dblclick_time
      });
/*
      rcmail.file_list.row_init = function(o){ kolab_files_init_file_row(o); };
      rcmail.file_list.addEventListener('dblclick', function(o){ p.msglist_dbl_click(o); });
      rcmail.file_list.addEventListener('click', function(o){ p.msglist_click(o); });
      rcmail.file_list.addEventListener('keypress', function(o){ p.msglist_keypress(o); });
*/
      rcmail.file_list.addEventListener('select', function(o){ kolab_files_list_select(o); });
/*
      rcmail.file_list.addEventListener('dragstart', function(o){ p.drag_start(o); });
      rcmail.file_list.addEventListener('dragmove', function(e){ p.drag_move(e); });
      rcmail.file_list.addEventListener('dragend', function(e){ p.drag_end(e); });
*/
      rcmail.file_list.addEventListener('column_replace', function(e){ kolab_files_set_coltypes(e); });
      rcmail.file_list.addEventListener('listupdate', function(e){ rcmail.triggerEvent('listupdate', e); });

//      document.onmouseup = function(e){ return p.doc_mouse_up(e); };
      rcmail.gui_objects.filelist.parentNode.onmousedown = function(e){ return kolab_files_click_on_list(e); };

      rcmail.enable_command('menu-open', 'menu-save', 'files-sort', 'files-search', 'files-search-reset', true);

      rcmail.file_list.init();
      kolab_files_list_coltypes();
    }

    rcmail.env.file_commands = ['files-get'];

    kolab_files_init();
    file_api.folder_list();
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

  file_api.translations = rcmail.labels;
};

function kolab_files_token()
{
  // consider the token from parent window more reliable (fresher) than in framed window
  // it's because keep-alive is not requested in frames
  return (window.parent && parent.rcmail && parent.rcmail.env.files_token) || rcmail.env.files_token;
};


/**********************************************************/
/*********  Plugin functionality in other tasks  **********/
/**********************************************************/

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
    modal: true,
    resizable: !bw.ie6,
    closeOnEscape: (!bw.ie6 && !bw.ie7),  // disable for performance reasons
    title: rcmail.gettext('kolab_files.saveall'),
//    close: function() { rcmail.dialog_close(); },
    buttons: buttons,
    minWidth: 250,
    minHeight: 300,
    height: 350,
    width: 300
    }).show();

  if (!rcmail.env.folders_loaded) {
    file_api.folder_list();
    rcmail.env.folders_loaded = true;
  }
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
    modal: true,
    resizable: !bw.ie6,
    closeOnEscape: (!bw.ie6 && !bw.ie7),  // disable for performance reasons
    title: rcmail.gettext('kolab_files.selectfiles'),
//    close: function() { rcmail.dialog_close(); },
    buttons: buttons,
    minWidth: 500,
    minHeight: 300,
    width: 700,
    height: 500
    }).show();

  if (!rcmail.env.files_loaded) {
    file_api.folder_list();
    rcmail.env.files_loaded = true;
  }
  else
    rcmail.file_list.clear_selection();
};


/***********************************************************/
/**********          Main functionality           **********/
/***********************************************************/

// for reordering column array (Konqueror workaround)
// and for setting some message list global variables
kolab_files_list_coltypes = function()
{
  var n, list = rcmail.file_list;

  rcmail.env.subject_col = null;

  if ((n = $.inArray('name', rcmail.env.coltypes)) >= 0) {
    rcmail.env.subject_col = n;
    list.subject_col = n;
  }

  list.init_header();
};

kolab_files_set_list_options = function(cols, sort_col, sort_order)
{
  var update = 0, i, idx, name, newcols = [], oldcols = rcmail.env.coltypes;

  if (sort_col === undefined)
    sort_col = rcmail.env.sort_col;
  if (!sort_order)
    sort_order = rcmail.env.sort_order;

  if (rcmail.env.sort_col != sort_col || rcmail.env.sort_order != sort_order) {
    update = 1;
    rcmail.set_list_sorting(sort_col, sort_order);
  }

  if (cols && cols.length) {
    // make sure new columns are added at the end of the list
    for (i=0; i<oldcols.length; i++) {
      name = oldcols[i];
      idx = $.inArray(name, cols);
      if (idx != -1) {
        newcols.push(name);
        delete cols[idx];
      }
    }
    for (i=0; i<cols.length; i++)
      if (cols[i])
        newcols.push(cols[i]);

    if (newcols.join() != oldcols.join()) {
      update += 2;
      oldcols = newcols;
    }
  }

  if (update == 1)
    file_api.file_list({sort: sort_col, reverse: sort_order == 'DESC'});
  else if (update) {
    rcmail.http_post('files/prefs', {
      kolab_files_list_cols: oldcols,
      kolab_files_sort_col: sort_col,
      kolab_files_sort_order: sort_order
      }, rcmail.set_busy(true, 'loading'));
  }
};

kolab_files_set_coltypes = function(list)
{
  var i, found, name, cols = list.list.tHead.rows[0].cells;

  rcmail.env.coltypes = [];

  for (i=0; i<cols.length; i++)
    if (cols[i].id && cols[i].id.match(/^rcm/)) {
      name = cols[i].id.replace(/^rcm/, '');
      rcmail.env.coltypes.push(name);
    }

//  if ((found = $.inArray('name', rcmail.env.coltypes)) >= 0)
//    rcmail.env.subject_col = found;
  rcmail.env.subject_col = list.subject_col;

  rcmail.http_post('files/prefs', {kolab_files_list_cols: rcmail.env.coltypes});
};

kolab_files_click_on_list = function(e)
{
  if (rcmail.gui_objects.qsearchbox)
    rcmail.gui_objects.qsearchbox.blur();

  if (rcmail.file_list)
    rcmail.file_list.focus();

  return true;
};

kolab_files_list_select = function(list)
{
  var selected = list.selection.length;

  rcmail.enable_command(rcmail.env.file_commands, selected == 1);

    // reset all-pages-selection
//  if (list.selection.length && list.selection.length != list.rowcount)
//    rcmail.select_all_mode = false;
};

rcube_webmail.prototype.files_sort = function(props)
{
  var params = {},
    sort_order = this.env.sort_order,
    sort_col = !this.env.disabled_sort_col ? props : this.env.sort_col;

  if (!this.env.disabled_sort_order)
    sort_order = this.env.sort_col == sort_col && sort_order == 'ASC' ? 'DESC' : 'ASC';

  // set table header and update env
  this.set_list_sorting(sort_col, sort_order);

  this.http_post('files/prefs', {kolab_files_sort_col: sort_col, kolab_files_sort_order: sort_order});

  params.sort = sort_col;
  params.reverse = sort_order == 'DESC';

  file_api.file_list(params);
};

rcube_webmail.prototype.files_search = function()
{
  var value = $(this.gui_objects.filesearchbox).val();

  if (value)
    file_api.file_search(value);
  else
    file_api.file_search_reset();
};

rcube_webmail.prototype.files_search_reset = function()
{
  $(this.gui_objects.filesearchbox).val('');

  file_api.file_search_reset();
};

rcube_webmail.prototype.files_folder_delete = function()
{
  if (confirm(this.get_label('deletefolderconfirm')))
    file_api.folder_delete(file_api.env.folder);
};

rcube_webmail.prototype.files_upload = function(form)
{
  if (form)
    file_api.file_upload(form);
};

rcube_webmail.prototype.files_list_update = function(head)
{
  var list = this.file_list;

  list.clear();
  $('thead', list.list).html(head);
  kolab_files_list_coltypes();
  file_api.file_list();
};

rcube_webmail.prototype.files_get = function()
{
  var id = this.file_list.get_selection();

  if (id = $('#rcmrow'+id).data('file')) {
    file_api.file_get(id, {'force-download': true});
  }
};


/**********************************************************/
/*********          Files API handler            **********/
/**********************************************************/

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

  this.folder_list = function()
  {
    this.req = this.set_busy(true, 'loading');
    this.get('folder_list', {}, 'folder_list_response');
  };

  // folder list response handler
  this.folder_list_response = function(response)
  {
    if (!this.response(response))
      return;

    var first, elem = $('#files-folder-list'),
      list = $('<ul class="listing"></ul>');

    elem.html('').append(list);

    this.env.folders = this.folder_list_parse(response.result);

    $.each(this.env.folders, function(i, f) {
      var row = $('<li class="mailbox"><span class="branch"></span><a></a></li>'),
        link = $('a', row);

      link.text(f.name);
      row.attr('id', f.id);

      if (f.depth)
        $('span.branch', row).width(15 * f.depth);

      if (f.virtual)
        row.addClass('virtual');
      else
        link.click(function() { file_api.folder_select(i); });

      list.append(row);

      if (!first)
        first = i;
    });

   // select first folder?
   if (this.env.folder || first)
     this.folder_select(this.env.folder ? this.env.folder : first);

    // add tree icons
    this.folder_list_tree(this.env.folders);
  };

  this.folder_select = function(i)
  {
    var list = $('#files-folder-list > ul');
    $('li.selected', list).removeClass('selected');
    $('#' + this.env.folders[i].id, list).addClass('selected');

    this.env.folder = i;

    rcmail.enable_command('files-folder-delete', 'files-upload', true);

    // list files in selected folder
    this.file_list();
  };

  this.file_list = function(params)
  {
    if (!this.env.folder || !rcmail.gui_objects.filelist)
      return;

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

    rcmail.enable_command(rcmail.env.file_commands, false);
    rcmail.file_list.clear();

    this.get('file_list', params, 'file_list_response');
  };

  // file list response handler
  this.file_list_response = function(response)
  {
    if (!this.response(response))
      return;

    var i = 0, table = $('#filelist');

    $('tbody', table).empty();

    $.each(response.result, function(key, data) {
      var c, row = '', col;

      i++;

      for (c in rcmail.env.coltypes) {
        c = rcmail.env.coltypes[c];
        if (c == 'name') {
          if (rcmail.env.task == 'files')
            col = '<td class="name">' + key + '</td>';
          else
            col = '<td class="name filename ' + file_api.file_type_class(data.type) + '">'
              + '<span>' + key + '</span></td>';
        }
        else if (c == 'mtime')
          col = '<td class="mtime">' + data.mtime + '</td>';
        else if (c == 'size')
          col = '<td class="size">' + file_api.file_size(data.size) + '</td>';
        else if (c == 'options')
          col = '<td class="filename ' + file_api.file_type_class(data.type) + '">'
            + '<span class="drop"><a href="#" onclick="kolab_files_file_menu(' + i + ')"></a></span></td>';
        else
          col = '<td class="' + c + '"></td>';

        row += col;
      }

      row = $('<tr>')
        .html(row)
        .attr({id: 'rcmrow' + i, 'data-file': urlencode(key)});

//      table.append(row);
      rcmail.file_list.insert_row(row.get([0]));
    });
  };

  this.file_select = function(e, row)
  {
    var table = $('#filelist');
//    $('tr.selected', table).removeClass('selected');
    $(row).addClass('selected');
  };

  // folder create request
  this.folder_create = function(folder)
  {
    this.req = this.set_busy(true, 'kolab_files.foldercreating');
    this.get('folder_create', {folder: folder}, 'folder_create_response');
  };

  // folder create response handler
  this.folder_create_response = function(response)
  {
    if (!this.response(response))
      return;

    // refresh folders list
    this.folder_list();
  };

  // folder delete request
  this.folder_delete = function(folder)
  {
    this.req = this.set_busy(true, 'folderdeleting');
    this.get('folder_delete', {folder: folder}, 'folder_delete_response');
  };

  // folder delete response handler
  this.folder_delete_response = function(response)
  {
    if (!this.response(response))
      return;

    this.env.folder = null;
    rcmail.enable_command('files-folder-delete', 'files-folder-rename', false);

    // refresh folders list
    this.folder_list();
  };

  this.file_search = function(value)
  {
    if (value) {
      this.env.search = {name: value};
      this.file_list({search: this.env.search});
    }
    else
      this.search_reset();
  };

  this.file_search_reset = function()
  {
    if (this.env.search) {
      this.env.search = null;
      this.file_list();
    }
  };

  this.file_get = function(file, params)
  {
    if (!params)
      params = {};

    params.folder = this.env.folder;
    params.token = this.env.token;
    params.file = file;

    rcmail.redirect(this.env.url + this.url('file_get', params));
  };

  // file upload request
  this.file_upload = function(form)
  {
    var form = $(form),
      field = $('input[type=file]', form).get(0),
      files = field.files ? field.files.length : field.value ? 1 : 0;

    if (files) {
      // submit form and read server response
      this.async_upload_form(form, 'file_create', function(event) {
        var doc, response;
        try {
          doc = this.contentDocument ? this.contentDocument : this.contentWindow.document;
          response = doc.body.innerHTML;
          // response may be wrapped in <pre> tag
          if (response.slice(0, 5).toLowerCase() == '<pre>' && response.slice(-6).toLowerCase() == '</pre>') {
            response = doc.body.firstChild.firstChild.nodeValue;
          }
          response = eval('(' + response + ')');
        } catch (err) {
          response = {status: 'ERROR'};
        }

        rcmail.hide_message(event.data.ts);

        // refresh the list on upload success
        if (file_api.response_parse(response))
          file_api.file_list();
      });
    }
  };

  // post the given form to a hidden iframe
  this.async_upload_form = function(form, action, onload)
  {
    var ts = rcmail.display_message(rcmail.get_label('kolab_files.uploading'), 'loading', 1000),
      frame_name = 'fileupload'+ts;
/*
    // upload progress support
    if (this.env.upload_progress_name) {
      var fname = this.env.upload_progress_name,
        field = $('input[name='+fname+']', form);

      if (!field.length) {
        field = $('<input>').attr({type: 'hidden', name: fname});
        field.prependTo(form);
      }
      field.val(ts);
    }
*/
    // have to do it this way for IE
    // otherwise the form will be posted to a new window
    if (document.all) {
      var html = '<iframe id="'+frame_name+'" name="'+frame_name+'"'
        + ' src="program/resources/blank.gif" style="width:0;height:0;visibility:hidden;"></iframe>';
      document.body.insertAdjacentHTML('BeforeEnd', html);
    }
    // for standards-compliant browsers
    else
      $('<iframe>')
        .attr({name: frame_name, id: frame_name})
        .css({border: 'none', width: 0, height: 0, visibility: 'hidden'})
        .appendTo(document.body);

    // handle upload errors, parsing iframe content in onload
    $('#'+frame_name).on('load', {ts:ts}, onload);

    $(form).attr({
      target: frame_name,
      action: this.env.url + this.url(action, {folder: this.env.folder, token: this.env.token, uploadid:ts}),
      method: 'POST'
    }).attr(form.encoding ? 'encoding' : 'enctype', 'multipart/form-data')
      .submit();
  };

};
