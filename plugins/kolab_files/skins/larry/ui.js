function kolab_files_ui_init()
{
  var filesviewsplit = new rcube_splitter({ id:'filesviewsplitter', p1:'#folderlistbox', p2:'#filelistcontainer',
    orientation:'v', relative:true, start:226, min:150, size:12 }).init();

  $(document).ready(function() {
    rcmail.addEventListener('menu-open', kolab_files_show_listoptions);
    rcmail.addEventListener('menu-save', kolab_files_save_listoptions);
  });

  kolab_files_upload_input('#filestoolbar a.upload');
};

function kolab_files_folder_form(link)
{
  var form = $('#files-folder-create');

  form.show();

  $('form > input[name="folder_name"]', form).val('').focus();
};

function kolab_directory_create()
{
  var folder = '',
    form = $('#folder-create-form'),
    name = $('input[name="folder_name"]', form).val(),
    parent = file_api.env.folder;
//    parent = $('input[name="folder_parent"]', form).is(':checked');

  if (!name)
    return;

  if (parent && file_api.env.folder)
    folder = file_api.env.folder + file_api.env.directory_separator;

  folder += name;

  kolab_directory_cancel();
  file_api.folder_create(folder);

  // todo: select created folder
};

function kolab_directory_cancel()
{
  var form = $('#files-folder-create');

  form.hide();
};

function kolab_files_show_listoptions()
{
  var $dialog = $('#listoptions');

  // close the dialog
  if ($dialog.is(':visible')) {
    $dialog.dialog('close');
    return;
  }

  // set form values
  $('input[name="sort_col"][value="'+rcmail.env.sort_col+'"]').prop('checked', true);
  $('input[name="sort_ord"][value="DESC"]').prop('checked', rcmail.env.sort_order == 'DESC');
  $('input[name="sort_ord"][value="ASC"]').prop('checked', rcmail.env.sort_order != 'DESC');

  // set checkboxes
  $('input[name="list_col[]"]').each(function() {
    $(this).prop('checked', $.inArray(this.value, rcmail.env.coltypes) != -1);
  });

  $dialog.dialog({
    modal: true,
    resizable: false,
    closeOnEscape: true,
    title: null,
    close: function() {
      $dialog.dialog('destroy').hide();
    },
    width: 650
  }).show();
};

function kolab_files_save_listoptions()
{
  $('#listoptions').dialog('close');

  var sort = $('input[name="sort_col"]:checked').val(),
    ord = $('input[name="sort_ord"]:checked').val(),
    cols = $('input[name="list_col[]"]:checked')
      .map(function(){ return this.value; }).get();

  kolab_files_set_list_options(cols, sort, ord);
};


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
