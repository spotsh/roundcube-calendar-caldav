/**
 *
 */
function rcube_owncloud()
{
	this.cloud;
	this.dialog;
}

// singleton getter
rcube_owncloud.instance = function()
{
	if (!rcube_owncloud._instance) {
		rcube_owncloud._instance = new rcube_owncloud;
	}

	return rcube_owncloud._instance;
};

// remote connector
rcube_owncloud.connect = function(client)
{
	rcube_owncloud.instance().connect(client);
};

rcube_owncloud.prototype = {

// callback from the ownCloud connector script loaded in the iframe
connect: function(client)
{
	var me = this;
	this.cloud = client;

	if (this.dialog)
		client.init_picker(this);
},

// callback function from FileAction in ownCloud widget
file_picked: function(dir, file)
{
	alert('Picked: ' + dir + '/' + file + '\n\nTODO: get direct access URL and paste it to the email message body.');

	if (this.dialog && this.dialog.is(':ui-dialog'))
		this.dialog.dialog('close');
},

// open a dialog showing the ownCloud widget
open_dialog: function(html)
{
	// request iframe code from server
	if (!this.dialog && !html) {
		var me = this;
		rcmail.addEventListener('plugin.owncloudembed', function(html){ me.open_dialog(html); });
		rcmail.http_request('owncloud/embed');
		return;
	}
	// create jQuery dialog with iframe
	else if (html) {
		var height = $(window).height() * 0.8;
		this.dialog = $('<div>')
			.addClass('owncloudembed')
			.css({ width:'100%', height:height+'px' })
			.appendTo(document.body)
			.html(html);

		this.dialog.dialog({
			modal: true,
			autoOpen: false,
			title: 'Select a file from the cloud',
			width: '80%',
			height: height
		});
	}

	// open the dialog
	if (this.dialog && this.dialog.is(':ui-dialog'))
		this.dialog.dialog('open');
}

};

// Roundcube startup
window.rcmail && rcmail.addEventListener('init', function(){
	// add a button for ownCloud file selection to compose screen
	if (rcmail.env.action == 'compose') {
		$('<a>')
			.addClass('button owncloudcompose')
			.html('ownCloud')
			.click(function(){ rcube_owncloud.instance().open_dialog(); return false; })
			.insertAfter('#compose-attachments input.button')
			.before('&nbsp;');
	}
})

