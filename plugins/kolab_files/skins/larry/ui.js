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
