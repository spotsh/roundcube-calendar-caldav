/**
 * Client scripts for the Kolab ActiveSync configuration utitlity
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

function kolab_activesync_config()
{
    /* private members */
    var me = this;
    var http_lock = null;
    var active_device = null;


    /* constructor */
    var devicelist = new rcube_list_widget(rcmail.gui_objects.devicelist,
        { multiselect:true, draggable:false, keyboard:true });
    devicelist.addEventListener('select', select_device);
    devicelist.init();

    rcmail.register_command('plugin.save-config', save_config);
    rcmail.register_command('plugin.delete-device', delete_device_config);
    rcmail.addEventListener('plugin.activesync_data_ready', device_data_ready);
    rcmail.addEventListener('plugin.activesync_save_complete', save_complete);

    $('input.alarm').change(function(e){ if (this.checked) $('#'+this.id.replace(/_alarm/, '')).prop('checked', this.checked); });
    $('input.subscription').change(function(e){ if (!this.checked) $('#'+this.id+'_alarm').prop('checked', false); });
    $(window).bind('resize', resize_ui);

    $('.subscriptionblock thead td.subscription img, .subscriptionblock thead td.alarm img').click(function(e){
      var $this = $(this);
      var classname = $this.parent().get(0).className;
      var check = !($this.data('checked') || false);
      $this.css('cursor', 'pointer').data('checked', check)
        .closest('table').find('input.'+classname).prop('checked', check).change();
    });

    // select first device
    if (rcmail.env.devicecount) {
      for (var imei in rcmail.env.devices)
        break;
      devicelist.select(imei);
    }

    /* private methods */
    function select_device(list)
    {
        active_device = list.get_single_selection();
        rcmail.enable_command('plugin.save-config', 'plugin.delete-device', true);

        if (active_device) {
            http_lock = rcmail.set_busy(true, 'loading');
            rcmail.http_request('plugin.activesyncjson', { cmd:'load', id:active_device }, http_lock);
        }

        $('#introtext').hide();
    }

    // callback from server after loading device data
    function device_data_ready(data)
    {
        // reset form first
        $('input.alarm:checked').prop('checked', false);
        $('input.subscription:checked').prop('checked', false).change();

        if (data.id && data.id == active_device) {
            $('#config-device-alias').val(data.devicealias);
//            $('#config-device-mode').val(data.syncmode);
//            $('#config-device-laxpic').prop('checked', data.laxpic ? true : false);

            $('input.subscription').each(function(i, elem){
                var key = elem.value;
                elem.checked = data.subscribed[key] ? true : false;
            }).change();
            $('input.alarm').each(function(i, elem){
                var key = elem.value;
                elem.checked = data.subscribed[key] == 2;
            });

            $('#configform, #prefs-box .boxtitle').show();
            resize_ui();
        }
        else {
            $('#configform, #prefs-box .boxtitle').hide();
        }
    }

    // submit current configuration form to server
    function save_config()
    {
        // TODO: validate device info
        var data = {
            cmd: 'save',
            id: active_device,
            devicealias: $('#config-device-alias').val(),
//            syncmode: $('#config-device-mode option:selected').val(),
//            laxpic: $('#config-device-laxpic').get(0).checked ? 1 : 0
        };

        data.subscribed = {};
        $('input.subscription:checked').each(function(i, elem){
            data.subscribed[elem.value] = 1;
        });
        $('input.alarm:checked').each(function(i, elem){
            if (data.subscribed[elem.value])
                data.subscribed[elem.value] = 2;
        });

        http_lock = rcmail.set_busy(true, 'kolab_activesync.savingdata');
        rcmail.http_post('plugin.activesyncjson', data, http_lock);
    }

    // callback function when saving has completed
    function save_complete(p)
    {
        if (p.success && p.devicealias) {
            $('#devices-table tr.selected span.devicealias').html(p.devicealias);
            rcmail.env.devices[p.id].ALIAS = p.devicealias;
        }
    }

    // handler for delete commands
    function delete_device_config()
    {
        if (active_device && confirm(rcmail.gettext('devicedeleteconfirm', 'kolab_activesync'))) {
            http_lock = rcmail.set_busy(true, 'kolab_activesync.savingdata');
            rcmail.http_post('plugin.activesyncjson', { cmd:'delete', id:active_device }, http_lock);
        }
    }

    // handler for window resize events: sets max-height of folders list scroll container
    function resize_ui()
    {
        if (active_device) {
//@TODO: this doesn't work good with Larry
            var h = $(window).height(), pos = $('#foldersubscriptions').offset();
            $('#foldersubscriptions').css('max-height', (h - pos.top - 90) + 'px');
        }
    }
}


window.rcmail && rcmail.addEventListener('init', function(evt) {
    var ACTION_CONFIG = 'plugin.activesyncconfig';

    // add button to tabs list
    var tab = $('<span>').attr('id', 'settingstabpluginactivesyncconfig').addClass('tablink');
    var button = $('<a>').attr('href', rcmail.env.comm_path+'&_action=plugin.activesyncconfig').html(rcmail.gettext('tabtitle', 'kolab_activesync')).appendTo(tab);
    rcmail.add_element(tab, 'tabs');

    if (rcmail.env.action == ACTION_CONFIG)
        new kolab_activesync_config();
});


// extend jQuery
(function($){
  $.fn.serializeJSON = function(){
    var json = {};
    jQuery.map($(this).serializeArray(), function(n, i) {
      json[n['name']] = n['value'];
    });
    return json;
  };
})(jQuery);
