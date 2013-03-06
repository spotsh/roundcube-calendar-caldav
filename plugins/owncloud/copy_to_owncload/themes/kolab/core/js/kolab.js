
function kolab_connector()
{
	var remote;

	// public members
	this.window = window;

	// export public methods
	this.init = init;
	this.init_picker = init_picker;
	this.list_files = list_files;

	function init(rcube)
	{
		remote = rcube;
	}

	function init_picker(rcube)
	{
		remote = rcube;
		
		if (window.FileActions) {
			// reset already registered actions
			// FileActions.actions.file = {};

			FileActions.register('file','Pick', OC.PERMISSION_READ, '', function(filename){
				var dir = $('#dir').val();
				remote.file_picked(dir, filename);
			});
			FileActions.setDefault('file', 'Pick');
		}
	}

	function list_files()
	{
		var files = [];
		$('#fileList tr').each(function(item){
			var row = $(item),
				type = row.attrib('data-type'),
				file = row.attrib('data-file'),
				mime = row.attrib('data-mime');
			
			if (type == 'file') {
				files.push(file);
			}
		});

		return files;
	}
}

$(document).ready(function(){
	// connect with Roundcube running in parent window
	if (window.parent && parent.rcmail && parent.rcube_owncloud) {
		parent.rcube_owncloud.connect(new kolab_connector());
	}
});