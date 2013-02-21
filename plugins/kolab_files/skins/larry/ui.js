function kolab_files_folder_form(link)
{
  var form = $('#files-folder-create'),
    link = $('#folder-create-start-button');

  link.hide();
  form.show();

  $('input[name="folder_name"]', form).val('').focus();
}

function kolab_directory_create()
{
  var folder = '',
    form = $('#files-folder-create'),
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
}

function kolab_directory_cancel()
{
  var form = $('#files-folder-create'),
    link = $('#folder-create-start-button');

  link.show();
  form.hide();
}
