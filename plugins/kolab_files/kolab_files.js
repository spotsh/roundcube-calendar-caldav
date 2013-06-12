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

      rcmail.addEventListener('menu-open', kolab_files_attach_menu_open);
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
      rcmail.file_list.addEventListener('dragstart', function(o){ p.drag_start(o); });
      rcmail.file_list.addEventListener('dragmove', function(e){ p.drag_move(e); });
*/
      rcmail.file_list.addEventListener('dblclick', function(o){ kolab_files_list_dblclick(o); });
      rcmail.file_list.addEventListener('select', function(o){ kolab_files_list_select(o); });
      rcmail.file_list.addEventListener('dragend', function(e){ kolab_files_drag_end(e); });
      rcmail.file_list.addEventListener('column_replace', function(e){ kolab_files_set_coltypes(e); });
      rcmail.file_list.addEventListener('listupdate', function(e){ rcmail.triggerEvent('listupdate', e); });

//      document.onmouseup = function(e){ return p.doc_mouse_up(e); };
      rcmail.gui_objects.filelist.parentNode.onmousedown = function(e){ return kolab_files_click_on_list(e); };

      rcmail.enable_command('menu-open', 'menu-save', 'files-sort', 'files-search', 'files-search-reset', true);

      rcmail.file_list.init();
      kolab_files_list_coltypes();
    }

    // "one file only" commands
    rcmail.env.file_commands = ['files-get'];
    // "one or more file" commands
    rcmail.env.file_commands_all = ['files-delete', 'files-move', 'files-copy'];

    kolab_files_init();

    if (rcmail.env.action == 'open') {
      rcmail.enable_command('files-get', 'files-delete', rcmail.env.file);
    }
    else {
      file_api.folder_list();
      file_api.browser_capabilities_check();
    }
  }
});


/**********************************************************/
/*********          Shared functionality         **********/
/**********************************************************/

// Initializes API object
function kolab_files_init()
{
  if (window.file_api)
    return;

  // Initialize application object (don't change var name!)
  file_api = $.extend(new files_api(), new kolab_files_ui());

  file_api.set_env({
    token: kolab_files_token(),
    url: rcmail.env.files_url,
    sort_col: 'name',
    sort_reverse: false,
    search_threads: rcmail.env.search_threads,
    resources_dir: 'program/resources',
    supported_mimetypes: rcmail.env.file_mimetypes
  });

  file_api.translations = rcmail.labels;
};

// returns API authorization token
function kolab_files_token()
{
  // consider the token from parent window more reliable (fresher) than in framed window
  // it's because keep-alive is not requested in frames
  return (window.parent && parent.rcmail && parent.rcmail.env.files_token) || rcmail.env.files_token;
};

// folder selection dialog
function kolab_directory_selector_dialog(id)
{
  var dialog = $('#files-dialog'), buttons = {},
    input = $('#file-save-as-input'),
    form = $('#file-save-as'),
    list = $('#folderlistbox');

  // attachment is specified
  if (id) {
    var attach = $('#attach'+id), filename = attach.attr('title') || attach.text();
    form.show();
    dialog.addClass('saveas');
    input.val(filename);
  }
  else {
    form.hide();
    dialog.removeClass('saveas');
  }

  buttons[rcmail.gettext('kolab_files.save')] = function () {
    var lock = rcmail.set_busy(true, 'saving'),
      request = {
        act: 'save-file',
        source: rcmail.env.mailbox,
        uid: rcmail.env.uid,
        dest: file_api.env.folder
      };

    if (id) {
      request.id = id;
      request.name = input.val();
    }

    rcmail.http_post('plugin.kolab_files', request, lock);
    dialog.dialog('destroy').hide();
  };
  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    dialog.dialog('destroy').hide();
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.' + (id ? 'saveto' : 'saveall')),
    buttons: buttons,
    minWidth: 250,
    minHeight: 300,
    height: 350,
    width: 300
  });

  if (!rcmail.env.folders_loaded) {
    file_api.folder_list();
    rcmail.env.folders_loaded = true;
  }
};

// file selection dialog
function kolab_files_selector_dialog()
{
  var dialog = $('#files-compose-dialog'), buttons = {};

  buttons[rcmail.gettext('kolab_files.attachsel')] = function () {
    var list = [];
    $('#filelist tr.selected').each(function() {
      list.push($(this).data('file'));
    });

    dialog.dialog('destroy').hide();

    if (list.length) {
      // display upload indicator and cancel button
      var content = '<span>' + rcmail.get_label('kolab_files.attaching') + '</span>',
        id = new Date().getTime();

      rcmail.add2attachment_list(id, {name:'', html:content, classname:'uploading', complete:false});

      // send request
      rcmail.http_post('plugin.kolab_files', {
        act: 'attach-file',
        files: list,
        id: rcmail.env.compose_id,
        uploadid: id
      });
    }
  };
  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    dialog.dialog('destroy').hide();
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.selectfiles'),
    buttons: buttons,
    minWidth: 500,
    minHeight: 300,
    width: 700,
    height: 500
  });

  if (!rcmail.env.files_loaded) {
    file_api.folder_list();
    rcmail.env.files_loaded = true;
  }
  else
    rcmail.file_list.clear_selection();
};

function kolab_files_attach_menu_open(p)
{
  if (!p || !p.props || p.props.menu != 'attachmentmenu')
    return;

  var id = p.props.id;

  $('#attachmenusaveas').unbind('click').attr('onclick', '').click(function(e) {
    return kolab_directory_selector_dialog(id);
  });
};

// folder creation dialog
function kolab_files_folder_create_dialog()
{
  var dialog = $('#files-folder-create-dialog'),
    buttons = {},
    select = $('select[name="parent"]', dialog).html(''),
    input = $('input[name="name"]', dialog).val('');

  buttons[rcmail.gettext('kolab_files.create')] = function () {
    var folder = '', name = input.val(), parent = select.val();

    if (!name)
      return;

    if (parent)
      folder = parent + file_api.env.directory_separator;

    folder += name;

    file_api.folder_create(folder);
    dialog.dialog('destroy').hide();
  };
  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    dialog.dialog('destroy').hide();
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.foldercreate'),
    buttons: buttons,
    minWidth: 400,
    minHeight: 300,
    width: 500,
    height: 400
  });

  // build parent selector
  select.append($('<option>').val('').text('---'));
  $.each(file_api.env.folders, function(i, f) {
    var n, option = $('<option>'), name = escapeHTML(f.name);

    for (n=0; n<f.depth; n++)
      name = '&nbsp;&nbsp;&nbsp;' + name;

    option.val(i).html(name).appendTo(select);

    if (i == file_api.env.folder)
      option.attr('selected', true);
  });
};

// file edition dialog
function kolab_files_file_edit_dialog(file)
{
  var dialog = $('#files-file-edit-dialog'),
    buttons = {}, name = file_api.file_name(file)
    input = $('input[name="name"]', dialog).val(name);

  buttons[rcmail.gettext('kolab_files.save')] = function () {
    var folder = file_api.file_path(file), name = input.val();

    if (!name)
      return;

    name = folder + file_api.env.directory_separator + name;

    // @TODO: now we only update filename
    if (name != file)
      file_api.file_rename(file, name);
    dialog.dialog('destroy').hide();
  };
  buttons[rcmail.gettext('kolab_files.cancel')] = function () {
    dialog.dialog('destroy').hide();
  };

  // show dialog window
  kolab_dialog_show(dialog, {
    title: rcmail.gettext('kolab_files.fileedit'),
    buttons: buttons
  });
};

function kolab_dialog_show(dialog, params)
{
  params = $.extend({
    modal: true,
    resizable: !bw.ie6,
    closeOnEscape: (!bw.ie6 && !bw.ie7),  // disabled for performance reasons
    minWidth: 400,
    minHeight: 300,
    width: 500,
    height: 400
  }, params || {});

  dialog.dialog(params).show();
};

// smart upload button
function kolab_files_upload_input(button)
{
  var link = $(button),
    file = $('<input>'),
    offset = link.offset();

  file.attr({name: 'file[]', type: 'file', multiple: 'multiple', size: 5})
    .change(function() { rcmail.files_upload('#filesuploadform'); })
    // opacity:0 does the trick, display/visibility doesn't work
    .css({opacity: 0, cursor: 'pointer', outline: 'none', position: 'absolute', top: '10000px', left: '10000px'});

  // In FF and IE we need to move the browser file-input's button under the cursor
  // Thanks to the size attribute above we know the length of the input field
  if (bw.mz || bw.ie)
    file.css({marginLeft: '-80px'});

  // Note: now, I observe problem with cursor style on FF < 4 only
  link.css({overflow: 'hidden', cursor: 'pointer'})
    // place button under the cursor
    .mousemove(function(e) {
      if (rcmail.commands['files-upload'])
        file.css({top: (e.pageY - offset.top - 10) + 'px', left: (e.pageX - offset.left - 10) + 'px'});
      // move the input away if button is disabled
      else
        $(this).mouseleave();
    })
    .mouseleave(function() { file.css({top: '10000px', left: '10000px'}); })
    .attr('onclick', '') // remove default button action
    .append(file);
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
    rcmail.command('files-list', {sort: sort_col, reverse: sort_order == 'DESC'});
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

kolab_files_list_dblclick = function(list)
{
  rcmail.command('files-open');
};

kolab_files_list_select = function(list)
{
  var selected = list.selection.length;

  rcmail.enable_command(rcmail.env.file_commands_all, selected);
  rcmail.enable_command(rcmail.env.file_commands, selected == 1);

    // reset all-pages-selection
//  if (list.selection.length && list.selection.length != list.rowcount)
//    rcmail.select_all_mode = false;

  // enable files-
  if (selected == 1) {
    // get file mimetype
    var type = $('tr.selected', list.list).data('type');
    rcmail.env.viewer = file_api.file_type_supported(type);
  }
  else
    rcmail.env.viewer = 0;
/*
    ) {
//      caps = this.browser_capabilities().join();
      href = '?' + $.param({_task: 'files', _action: 'open', file: file, viewer: viewer == 2 ? 1 : 0});
      var win = window.open(href, rcmail.html_identifier('rcubefile'+file));
      if (win)
        setTimeout(function() { win.focus(); }, 10);
    }
*/
  rcmail.enable_command('files-open', rcmail.env.viewer);
};

kolab_files_drag_end = function(e)
{
  var folder = $('#files-folder-list li.droptarget').removeClass('droptarget');

  if (folder.length) {
    folder = folder.data('folder');

    var modkey = rcube_event.get_modifier(e),
      menu = rcmail.gui_objects.file_dragmenu;

    if (menu && modkey == SHIFT_KEY && rcmail.commands['files-copy']) {
      var pos = rcube_event.get_mouse_pos(e);
      rcmail.env.drag_target = folder;
      $(menu).css({top: (pos.y-10)+'px', left: (pos.x-10)+'px'}).show();
      return;
    }

    rcmail.command('files-move', folder);
  }
};

kolab_files_drag_menu_action = function(command)
{
  var menu = rcmail.gui_objects.file_dragmenu;

  if (menu)
    $(menu).hide();

  rcmail.command(command, rcmail.env.drag_target);
};

kolab_files_selected = function()
{
  var files = [];
  $.each(rcmail.file_list.get_selection(), function(i, v) {
    var name, row = $('#rcmrow'+v);

    if (row.length == 1 && (name = row.data('file')))
      files.push(name);
  });

  return files;
};

kolab_files_frame_load = function(frame)
{
  var win = frame.contentWindow;

  rcmail.file_editor = win.file_editor && win.file_editor.editable ? win.file_editor : null;

  if (rcmail.file_editor)
    rcmail.enable_command('files-edit', true);
};


/***********************************************************/
/**********              Commands                 **********/
/***********************************************************/

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

  this.command('files-list', params);
};

rcube_webmail.prototype.files_search = function()
{
  var value = $(this.gui_objects.filesearchbox).val();

  if (value)
    file_api.file_search(value, $('#search_all_folders').is(':checked'));
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
  if (confirm(this.get_label('kolab_files.folderdeleteconfirm')))
    file_api.folder_delete(file_api.env.folder);
};

rcube_webmail.prototype.files_delete = function()
{
  if (!confirm(this.get_label('kolab_files.filedeleteconfirm')))
    return;

  var files = this.env.file ? [this.env.file] : kolab_files_selected();
  file_api.file_delete(files);
};

rcube_webmail.prototype.files_move = function(folder)
{
  var files = kolab_files_selected();
  file_api.file_move(files, folder);
};

rcube_webmail.prototype.files_copy = function(folder)
{
  var files = kolab_files_selected();
  file_api.file_copy(files, folder);
};

rcube_webmail.prototype.files_upload = function(form)
{
  if (form)
    file_api.file_upload(form);
};

rcube_webmail.prototype.files_list = function(param)
{
  // just rcmail wrapper, to handle command busy states
  file_api.file_list(param);
}

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
  var files = this.env.file ? [this.env.file] : kolab_files_selected();

  if (files.length == 1)
    file_api.file_get(files[0], {'force-download': true});
};

rcube_webmail.prototype.files_open = function()
{
  var files = kolab_files_selected();

  if (files.length == 1)
    file_api.file_open(files[0], rcmail.env.viewer);
};

// enable file editor
rcube_webmail.prototype.files_edit = function()
{
  if (this.file_editor) {
    this.file_editor.enable();
    this.enable_command('files-save', true);
  }
};

rcube_webmail.prototype.files_save = function()
{
  if (!this.file_editor)
    return;

  var content = this.file_editor.getContent();

  file_api.file_save(this.env.file, content);
};

rcube_webmail.prototype.files_set_quota = function(p)
{
  if (p.total) {
    p.used *= 1024;
    p.total *= 1024;
    p.title = file_api.file_size(p.used) + ' / ' + file_api.file_size(p.total)
        + ' (' + p.percent + '%)';
  }

  p.type = this.env.quota_type;

  this.set_quota(p);
};


/**********************************************************/
/*********          Files API handler            **********/
/**********************************************************/

function kolab_files_ui()
{
  this.requests = {};

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
  this.display_message = function(label, type)
  {
    return rcmail.display_message(this.t(label), type);
  };

  this.http_error = function(request, status, err)
  {
    rcmail.http_error(request, status, err);
  };

  // folders list request
  this.folder_list = function()
  {
    this.req = this.set_busy(true, 'loading');
    this.request('folder_list', {}, 'folder_list_response');
  };

  // folder list response handler
  this.folder_list_response = function(response)
  {
    if (!this.response(response))
      return;

    var first, elem = $('#files-folder-list'),
      list = $('<ul class="listing"></ul>'),
      collections = !rcmail.env.action.match(/^(preview|show)$/) ? ['audio', 'video', 'image', 'document'] : [];

    elem.html('').append(list);

    this.env.folders = this.folder_list_parse(response.result);

    $.each(this.env.folders, function(i, f) {
      var row = $('<li class="mailbox"><span class="branch"></span></li>');

      row.attr('id', f.id).data('folder', i)
        .append($('<span class="name"></span>').text(f.name))
        .click(function() { file_api.folder_select(i); });

      if (f.depth)
        $('span.branch', row).width(15 * f.depth);

      if (f.virtual)
        row.addClass('virtual');
      else
        row.mouseenter(function() {
            if (rcmail.file_list && rcmail.file_list.drag_active && !$(this).hasClass('selected'))
              $(this).addClass('droptarget');
          })
          .mouseleave(function() {
            if (rcmail.file_list && rcmail.file_list.drag_active)
              $(this).removeClass('droptarget');
          });

      list.append(row);

      if (!first)
        first = i;
    });

    // add virtual collections
    $.each(collections, function(i, n) {
      var row = $('<li class="mailbox collection ' + n + '"></li>');

      row.attr('id', 'folder-collection-' + n)
        .append($('<span class="name"></span>').text(rcmail.gettext('kolab_files.collection_' + n)))
        .click(function() { file_api.folder_select(n, true); });

      list.append(row);
    });

   // select first folder?
   if (this.env.folder)
     this.folder_select(this.env.folder);
   else if (this.env.collection)
     this.folder_select(this.env.collection, true);
   else if (first)
     this.folder_select(first);

    // add tree icons
    this.folder_list_tree(this.env.folders);
  };

  this.folder_select = function(folder, is_collection)
  {
    var list = $('#files-folder-list > ul');

    if (rcmail.busy)
      return;

    $('li.selected', list).removeClass('selected');

    rcmail.enable_command('files-list', true);

    if (is_collection) {
      var found = $('#folder-collection-' + folder, list).addClass('selected');

      rcmail.enable_command('files-folder-delete', 'files-upload', false);
      this.env.folder = null;
      rcmail.command('files-list', {collection: folder});
    }
    else {
      var found = $('#' + this.env.folders[folder].id, list).addClass('selected');

      rcmail.enable_command('files-folder-delete', 'files-upload', true);
      this.env.folder = folder;
      this.env.collection = null;
      rcmail.command('files-list', {folder: folder});
    }

    this.quota();
  };

  this.folder_unselect = function()
  {
    var list = $('#files-folder-list > ul');
    $('li.selected', list).removeClass('selected');
    rcmail.enable_command('files-folder-delete', 'files-upload', false);
    this.env.folder = null;
    this.env.collection = null;
  };

  // folder create request
  this.folder_create = function(folder)
  {
    this.req = this.set_busy(true, 'kolab_files.foldercreating');
    this.request('folder_create', {folder: folder}, 'folder_create_response');
  };

  // folder create response handler
  this.folder_create_response = function(response)
  {
    if (!this.response(response))
      return;

    this.display_message('kolab_files.foldercreatenotice', 'confirmation');

    // refresh folders list
    this.folder_list();
  };

  // folder delete request
  this.folder_delete = function(folder)
  {
    this.req = this.set_busy(true, 'kolab_files.folderdeleting');
    this.request('folder_delete', {folder: folder}, 'folder_delete_response');
  };

  // folder delete response handler
  this.folder_delete_response = function(response)
  {
    if (!this.response(response))
      return;

    this.env.folder = null;
    rcmail.enable_command('files-folder-delete', 'files-folder-rename', 'files-list', false);
    this.display_message('kolab_files.folderdeletenotice', 'confirmation');

    // refresh folders list
    this.folder_list();
    this.quota();
  };

  // quota request
  this.quota = function()
  {
    if (rcmail.env.files_quota)
      this.request('quota', {folder: this.env.folder}, 'quota_response');
  };

  // quota response handler
  this.quota_response = function(response)
  {
    if (!this.response(response))
      return;

    rcmail.files_set_quota(response.result);
  };

  this.file_list = function(params)
  {
    if (!rcmail.gui_objects.filelist)
      return;

    if (!params)
      params = {};

    // reset all pending list requests
    for (i in this.requests) {
      this.requests[i].abort();
      rcmail.hide_message(i);
      delete this.requests[i];
    }

    if (params.all_folders) {
      params.collection = null;
      params.folder = null;
      this.folder_unselect();
    }

    if (params.collection == undefined)
      params.collection = this.env.collection;
    if (params.folder == undefined)
      params.folder = this.env.folder;
    if (params.sort == undefined)
      params.sort = this.env.sort_col;
    if (params.reverse == undefined)
      params.reverse = this.env.sort_reverse;
    if (params.search == undefined)
      params.search = this.env.search;

    this.env.folder = params.folder;
    this.env.collection = params.collection;
    this.env.sort_col = params.sort;
    this.env.sort_reverse = params.reverse;

    rcmail.enable_command(rcmail.env.file_commands, false);
    rcmail.enable_command(rcmail.env.file_commands_all, false);

    // empty the list
    this.env.file_list = [];
    rcmail.file_list.clear(true);

    // request
    if (params.collection || params.all_folders)
      this.file_list_loop(params);
    else if (this.env.folder) {
      params.req_id = this.set_busy(true, 'loading');
      this.requests[params.req_id] = this.request('file_list', params, 'file_list_response');
    }
  };

  // file list response handler
  this.file_list_response = function(response)
  {
    if (response.req_id)
      rcmail.hide_message(response.req_id);

    if (!this.response(response))
      return;

    var i = 0, list = [], table = $('#filelist');

    $.each(response.result, function(key, data) {
      var row = file_api.file_list_row(key, data, ++i);
      rcmail.file_list.insert_row(row);
      data.row = row;
      data.filename = key;
      list.push(data);
    });

    this.env.file_list = list;
  };

  // call file_list request for every folder (used for search and virt. collections)
  this.file_list_loop = function(params)
  {
    var i, folders = [], limit = Math.max(this.env.search_threads || 1, 1);

    if (params.collection) {
      if (!params.search)
        params.search = {};
      params.search['class'] = params.collection;
      delete params['collection'];
    }

    delete params['all_folders'];

    $.each(this.env.folders, function(i, f) {
      if (!f.virtual)
        folders.push(i);
    });

    this.env.folders_loop = folders;
    this.env.folders_loop_params = params;
    this.env.folders_loop_lock = false;

    for (i=0; i<folders.length && i<limit; i++) {
      params.req_id = this.set_busy(true, 'loading');
      params.folder = folders.shift();
      this.requests[params.req_id] = this.request('file_list', params, 'file_list_loop_response');
    }
  };

  // file list response handler for loop'ed request
  this.file_list_loop_response = function(response)
  {
    var i, folders = this.env.folders_loop,
      params = this.env.folders_loop_params,
      limit = Math.max(this.env.search_threads || 1, 1),
      valid = this.response(response);

    if (response.req_id)
      rcmail.hide_message(response.req_id);

    for (i=0; i<folders.length && i<limit; i++) {
      params.req_id = this.set_busy(true, 'loading');
      params.folder = folders.shift();
      this.requests[params.req_id] = this.request('file_list', params, 'file_list_loop_response');
    }

    if (!valid)
      return;

    this.file_list_loop_result_add(response.result);
  };

  // add files from list request to the table (with sorting)
  this.file_list_loop_result_add = function(result)
  {
    // chack if result (hash-array) is empty
    if (!object_is_empty(result))
      return;

    if (this.env.folders_loop_lock) {
      setTimeout(function() { file_api.file_list_loop_result_add(result); }, 100);
      return;
    }

    // lock table, other list responses will wait
    this.env.folders_loop_lock = true;

    var n, i, len, elem, list = [], rows = [],
      index = this.env.file_list.length,
      table = rcmail.file_list;

    for (n=0, len=index; n<len; n++) {
      elem = this.env.file_list[n];
      for (i in result) {
        if (this.sort_compare(elem, result[i]) < 0)
          break;

        var row = this.file_list_row(i, result[i], ++index);
        table.insert_row(row, elem.row);
        result[i].row = row;
        result[i].filename = i;
        list.push(result[i]);
        delete result[i];
      }

      list.push(elem);
    }

    // add the rest of rows
    $.each(result, function(key, data) {
      var row = file_api.file_list_row(key, data, ++index);
      table.insert_row(row);
      result[key].row = row;
      result[key].filename = key;
      list.push(result[key]);
    });

    this.env.file_list = list;
    this.env.folders_loop_lock = false;
  };

  // sort files list (without API request)
  this.file_list_sort = function(col, reverse)
  {
    var n, len, list = this.env.file_list,
      table = $('#filelist'), tbody = $('<tbody>');

    this.env.sort_col = col;
    this.env.sort_reverse = reverse;

    if (!list || !list.length)
      return;

    // sort the list
    list.sort(function (a, b) {
      return file_api.sort_compare(a, b);
    });

    // add rows to the new body
    for (n=0, len=list.length; n<len; n++) {
      tbody.append(list[n].row);
    }

    // replace table bodies
    $('tbody', table).replaceWith(tbody);
  };

  this.file_list_row = function(file, data, index)
  {
    var c, col, row = '';

    for (c in rcmail.env.coltypes) {
      c = rcmail.env.coltypes[c];
      if (c == 'name')
        col = '<td class="name filename ' + this.file_type_class(data.type) + '">'
          + '<span>' + escapeHTML(data.name) + '</span></td>';
      else if (c == 'mtime')
        col = '<td class="mtime">' + data.mtime + '</td>';
      else if (c == 'size')
        col = '<td class="size">' + this.file_size(data.size) + '</td>';
      else if (c == 'options')
        col = '<td class="options"><span></span></td>';
      else
        col = '<td class="' + c + '"></td>';

      row += col;
    }

    row = $('<tr>')
      .html(row)
      .attr({id: 'rcmrow' + index, 'data-file': file, 'data-type': data.type});

    $('td.options > span', row).click(function(e) {
      kolab_files_file_edit_dialog(file);
    });

    // collection (or search) lists files from all folders
    // display file name with full path as title
    if (!this.env.folder)
      $('td.name span', row).attr('title', file);

    return row.get(0);
  };

  this.file_search = function(value, all_folders)
  {
    if (value) {
      this.env.search = {name: value};
      rcmail.command('files-list', {search: this.env.search, all_folders: all_folders});
    }
    else
      this.search_reset();
  };

  this.file_search_reset = function()
  {
    if (this.env.search) {
      this.env.search = null;
      rcmail.command('files-list');
    }
  };

  this.file_get = function(file, params)
  {
    if (!params)
      params = {};

    params.token = this.env.token;
    params.file = file;

    rcmail.redirect(this.env.url + this.url('file_get', params));
  };

  // file(s) delete request
  this.file_delete = function(files)
  {
    this.req = this.set_busy(true, 'kolab_files.filedeleting');
    this.request('file_delete', {file: files}, 'file_delete_response');
  };

  // file(s) delete response handler
  this.file_delete_response = function(response)
  {
    if (!this.response(response))
      return;

    this.display_message('kolab_files.filedeletenotice', 'confirmation');
    if (rcmail.env.file) {
      // @TODO: reload files list in parent window
      window.close();
    }
    else {
      this.file_list();
      this.quota();
    }
  };

  // file(s) move request
  this.file_move = function(files, folder)
  {
    if (!files || !files.length || !folder)
      return;

    var count = 0, list = {};

    $.each(files, function(i, v) {
      var name = folder + file_api.env.directory_separator + file_api.file_name(v);

      if (name != v) {
        list[v] = name;
        count++;
      }
    });

    if (!count)
      return;

    this.req = this.set_busy(true, 'kolab_files.filemoving');
    this.request('file_move', {file: list}, 'file_move_response');
  };

  // file(s) move response handler
  this.file_move_response = function(response)
  {
    if (!this.response(response))
      return;

    if (response.result && response.result.already_exist && response.result.already_exist.length)
      this.file_move_ask_user(response.result.already_exist, true);
    else {
      this.display_message('kolab_files.filemovenotice', 'confirmation');
      this.file_list();
    }
  };

  // file(s) copy request
  this.file_copy = function(files, folder)
  {
    if (!files || !files.length || !folder)
      return;

    var count = 0, list = {};

    $.each(files, function(i, v) {
      var name = folder + file_api.env.directory_separator + file_api.file_name(v);

      if (name != v) {
        list[v] = name;
        count++;
      }
    });

    if (!count)
      return;

    this.req = this.set_busy(true, 'kolab_files.filecopying');
    this.request('file_copy', {file: list}, 'file_copy_response');
  };

  // file(s) copy response handler
  this.file_copy_response = function(response)
  {
    if (!this.response(response))
      return;

    if (response.result && response.result.already_exist && response.result.already_exist.length)
      this.file_move_ask_user(response.result.already_exist);
    else {
      this.display_message('kolab_files.filecopynotice', 'confirmation');
      this.quota();
    }
  };

  // when file move/copy operation returns file-exists error
  // this displays a dialog where user can decide to skip
  // or overwrite destination file(s)
  this.file_move_ask_user = function(list, move)
  {
    var file = list[0], buttons = {},
      text = rcmail.gettext('kolab_files.filemoveconfirm').replace('$file', file.dst)
      dialog = $('<div></div>');

    buttons[rcmail.gettext('kolab_files.fileoverwrite')] = function() {
      var file = list.shift(), f = {},
        action = move ? 'file_move' : 'file_copy';

      f[file.src] = file.dst;
      file_api.file_move_ask_list = list;
      file_api.file_move_ask_mode = move;
      dialog.dialog('destroy').remove();
      file_api.req = file_api.set_busy(true, move ? 'kolab_files.filemoving' : 'kolab_files.filecopying');
      file_api.request(action, {file: f, overwrite: 1}, 'file_move_ask_user_response');
    };

    if (list.length > 1)
      buttons[rcmail.gettext('kolab_files.fileoverwriteall')] = function() {
        var f = {}, action = move ? 'file_move' : 'file_copy';

        $.each(list, function() { f[this.src] = this.dst; });
        dialog.dialog('destroy').remove();
        file_api.req = file_api.set_busy(true, move ? 'kolab_files.filemoving' : 'kolab_files.filecopying');
        file_api.request(action, {file: f, overwrite: 1}, action + '_response');
      };

    var skip_func = function() {
      list.shift();
      dialog.dialog('destroy').remove();

      if (list.length)
        file_api.file_move_ask_user(list, move);
      else if (move)
        file_api.file_list();
    };

    buttons[rcmail.gettext('kolab_files.fileskip')] = skip_func;

    if (list.length > 1)
      buttons[rcmail.gettext('kolab_files.fileskipall')] = function() {
      dialog.dialog('destroy').remove();
        if (move)
          file_api.file_list();
      };

    // open jquery UI dialog
    kolab_dialog_show(dialog.html(text), {
      close: skip_func,
      buttons: buttons,
      minWidth: 400,
      width: 400
    });
  };

  // file move (with overwrite) response handler
  this.file_move_ask_user_response = function(response)
  {
    var move = this.file_move_ask_mode, list = this.file_move_ask_list;

    this.response(response);

    if (list && list.length)
      this.file_move_ask_user(list, mode);
    else {
      this.display_message('kolab_files.file' + (move ? 'move' : 'copy') + 'notice', 'confirmation');
      if (move)
        this.file_list();
    }
  };

  // file(s) rename request
  this.file_rename = function(oldfile, newfile)
  {
    this.req = this.set_busy(true, 'kolab_files.fileupdating');
    this.request('file_move', {file: oldfile, 'new': newfile}, 'file_rename_response');
  };

  // file(s) move response handler
  this.file_rename_response = function(response)
  {
    if (!this.response(response))
      return;

    // @TODO: we could update metadata instead
    this.file_list();
  };

  // file upload request
  this.file_upload = function(form)
  {
    var form = $(form),
      field = $('input[type=file]', form).get(0),
      files = field.files ? field.files.length : field.value ? 1 : 0;

    if (files) {
      // submit form and read server response
      this.file_upload_form(form, 'file_upload', function(event) {
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
          file_api.quota();
      });
    }
  };

  // post the given form to a hidden iframe
  this.file_upload_form = function(form, action, onload)
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

  // open file in new window, using file API viewer
  this.file_open = function(file, viewer)
  {
    var href = '?' + $.param({_task: 'files', _action: 'open', file: file, viewer: viewer == 2 ? 1 : 0});
    rcmail.open_window(href, false, true);
  };

  // save file
  this.file_save = function(file, content)
  {
    rcmail.enable_command('files-save', false);
    // because we currently can edit only text files
    // and we do not expect them to be very big, we save
    // file in a very simple way, no upload progress, etc.
    this.req = this.set_busy(true, 'saving');
    this.request('file_update', {file: file, content: content, info: 1}, 'file_save_response');
  };

  // file save response handler
  this.file_save_response = function(response)
  {
    rcmail.enable_command('files-save', true);

    if (!this.response(response))
      return;

    // update file properties table
    var table = $('#fileinfobox table'), file = response.result;

    if (file) {
      $('td.filetype', table).text(file.type);
      $('td.filesize', table).text(this.file_size(file.size));
      $('td.filemtime', table).text(file.mtime);
    }
  };

};
