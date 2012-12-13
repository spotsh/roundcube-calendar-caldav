/**
 * Client scripts for the Kolab Delegation configuration utitlity
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2011-2012, Kolab Systems AG <contact@kolabsys.com>
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

window.rcmail && rcmail.addEventListener('init', function(evt) {
  if (rcmail.env.task == 'mail') {
    // set delegator context for calendar requests on invitation message
    rcmail.addEventListener('requestcalendar/event', function(o) { rcmail.event_delegator_request(o); });
    rcmail.addEventListener('requestcalendar/mailimportevent', function(o) { rcmail.event_delegator_request(o); });
  }
  else if (rcmail.env.task != 'settings')
    return;

  // add Delegation section to the list
  var tab = $('<span>').attr('id', 'settingstabplugindelegation').addClass('tablink'),
    button = $('<a>').attr('href', rcmail.env.comm_path+'&_action=plugin.delegation')
      .html(rcmail.gettext('tabtitle', 'kolab_delegation'))
      .appendTo(tab);
  rcmail.add_element(tab, 'tabs');

  if (/^plugin.delegation/.test(rcmail.env.action)) {
    rcmail.addEventListener('plugin.delegate_save_complete', function(e) { rcmail.delegate_save_complete(e); });

    if (rcmail.gui_objects.delegatelist) {
      rcmail.delegatelist = new rcube_list_widget(rcmail.gui_objects.delegatelist,
        { multiselect:true, draggable:false, keyboard:true });
      rcmail.delegatelist.addEventListener('select', function(o) { rcmail.select_delegate(o); });
      rcmail.delegatelist.init();

      rcmail.enable_command('delegate-add', true);
    }
    else {
      rcmail.enable_command('delegate-save', true);

      var input = $('#delegate');

      // delegate autocompletion
      if (input.length) {
        rcmail.init_address_input_events(input, {action: 'settings/plugin.delegation-autocomplete'});
        rcmail.env.recipients_delimiter = '';
        input.focus();
      }

      // folders list
      $('input.write').change(function(e) {
        if (this.checked)
          $('input.read', this.parentNode.parentNode).prop('checked', true);
        });

      $('input.read').change(function(e) {
        if (!this.checked)
          $('input.write', this.parentNode.parentNode).prop('checked', false);
      });

      $('.foldersblock thead td img').click(function(e) {
        var $this = $(this),
          classname = $this.parent().get(0).className,
          list = $this.closest('table').find('input.'+classname),
          check = list.not(':checked').length > 0;

        list.prop('checked', check).change();
      });
    }
  }
});


  // delegates list onclick even handler
rcube_webmail.prototype.select_delegate = function(list)
{
  this.env.active_delegate = list.get_single_selection();

  if (this.env.active_delegate)
    this.delegate_select(this.env.active_delegate);
  else if (this.env.contentframe)
    this.show_contentframe(false);
};

// select delegate
rcube_webmail.prototype.delegate_select = function(id)
{
  var win, target = window, url = '&_action=plugin.delegation';

  if (id)
    url += '&_id='+urlencode(id);
  else {
    this.show_contentframe(false);
    return;
  }

  if (win = this.get_frame_window(this.env.contentframe)) {
    target = win;
    url += '&_framed=1';
  }

  if (String(target.location.href).indexOf(url) >= 0)
    this.show_contentframe(true);
  else
    this.location_href(this.env.comm_path+url, target, true);
};

  // display new delegate form
rcube_webmail.prototype.delegate_add = function()
{
  var win, target = window, url = '&_action=plugin.delegation';

  this.delegatelist.clear_selection();
  this.env.active_delegate = null;
  this.show_contentframe(false);

  if (win = this.get_frame_window(this.env.contentframe)) {
    target = win;
    url += '&_framed=1';
  }

  this.location_href(this.env.comm_path+url, target, true);
};

  // handler for delete commands
rcube_webmail.prototype.delegate_delete = function()
{
  if (!this.env.active_delegate)
    return;

  var $dialog = $("#delegate-delete-dialog").addClass('uidialog'),
    buttons = {};

  buttons[this.gettext('no', 'kolab_delegation')] = function() {
    $dialog.dialog('close');
  };
  buttons[this.gettext('yes', 'kolab_delegation')] = function() {
    $dialog.dialog('close');
    var lock = rcmail.set_busy(true, 'kolab_delegation.savingdata');
    rcmail.http_post('plugin.delegation-delete', {id: rcmail.env.active_delegate,
      acl: $("#delegate-delete-dialog input:checked").length}, lock);
  }

  // open jquery UI dialog
  $dialog.dialog({
    modal: true,
    resizable: false,
    closeOnEscape: true,
    title: this.gettext('deleteconfirm', 'kolab_delegation'),
    close: function() { $dialog.dialog('destroy').hide(); },
    buttons: buttons,
    width: 400
  }).show();
};

  // submit delegate form to the server
rcube_webmail.prototype.delegate_save = function()
{
  var data = {id: this.env.active_delegate},
    lock = this.set_busy(true, 'kolab_delegation.savingdata');

  // new delegate
  if (!data.id) {
    data.newid = $('#delegate').val().replace(/(^\s+|[\s,]+$)/, '');
    if (data.newid.match(/\s*\(([^)]+)\)$/))
      data.newid = RegExp.$1;
  }

  data.folders = {};
  $('input.read:checked').each(function(i, elem) {
    data.folders[elem.value] = 1;
  });
  $('input.write:checked').each(function(i, elem) {
    data.folders[elem.value] = 2;
  });

  this.http_post('plugin.delegation-save', data, lock);
};

  // callback function when saving/deleting has completed successfully
rcube_webmail.prototype.delegate_save_complete = function(p)
{
  // delegate created
  if (p.created) {
    var input = $('#delegate'),
      row = $('<tr><td></td></tr>'),
      rc = this.is_framed() ? parent.rcmail : this;

    // remove delegate input
    input.parent().append($('<span></span>').text(p.name));
    input.remove();

    // add delegate row to the list
    row.attr('id', 'rcmrow'+p.created);
    $('td', row).text(p.name);

    rc.delegatelist.insert_row(row.get(0));
    rc.delegatelist.highlight_row(p.created);

    this.env.active_delegate = p.created;
    rc.env.active_delegate = p.created;
    rc.enable_command('delegate-delete', true);
  }
  // delegate updated
  else if (p.updated) {
    // do nothing
  }
  // delegate deleted
  else if (p.deleted) {
    this.env.active_delegate = null;
    this.delegate_select();
    this.delegatelist.remove_row(p.deleted);
    this.enable_command('delegate-delete', false);
  }
};

rcube_webmail.prototype.event_delegator_request = function(data)
{
  if (!this.env.delegator_context)
    return;

  if (typeof data === 'object')
    data._context = this.env.delegator_context;
  else
    data += '&_context=' + this.env.delegator_context;

  return data;
};
