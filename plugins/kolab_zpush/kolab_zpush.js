/**
 * Client scripts for the Kolab Z-Push configuration utitlity
 *
 * @version 0.1
 * @author Thomas Bruederli <roundcube@gmail.com>
 *
 * Copyright (C) 2011, Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 */

function kolab_zpush_config()
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
    
    rcmail.register_command('plugin.save-config', save_config, true);
    rcmail.addEventListener('plugin.zpush_data_ready', device_data_ready);
    rcmail.addEventListener('plugin.zpush_save_complete', save_complete);

    $('input.subscription').change(function(e){ $('#'+this.id+'_alarm').prop('disabled', !this.checked); });
    $(window).bind('resize', resize_ui);


    /* private methods */
    function select_device(list)
    {
        active_device = list.get_single_selection();
        rcmail.enable_command('plugin.save-config');
        
        if (active_device) {
            http_lock = rcmail.set_busy(true, 'loading');
            rcmail.http_request('plugin.zpushjson', { cmd:'load', id:active_device }, http_lock);
        }
        
        $('#introtext').hide();
    }

    // callback from server after loading device data
    function device_data_ready(data)
    {
        if (data.id && data.id == active_device) {
            $('#config-device-alias').val(data.devicealias);
            $('#config-device-mode').val(data.syncmode);
            
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
            syncmode: $('#config-device-mode option:selected').val()
        };

        data.subscribed = {};
        $('input.subscription:checked').each(function(i, elem){
            data.subscribed[elem.value] = 1;
        });
        $('input.alarm:checked').each(function(i, elem){
            if (data.subscribed[elem.value])
                data.subscribed[elem.value] = 2;
        });

        http_lock = rcmail.set_busy(true, 'kolab_zpush.savingdata');
        rcmail.http_post('plugin.zpushjson', data, http_lock);
    }

    // callback function when saving has completed
    function save_complete(p)
    {
        if (p.success && p.devicename) {
            $('#devices-table tr.selected span.devicealias').html(p.devicename);
        }
    }

    // handler for window resize events: sets max-height of folders list scroll container
    function resize_ui()
    {
        if (active_device) {
            var h = $(window).height();
            var pos = $('#folderscrollist').offset();
            $('#folderscrollist').css('max-height', (h - pos.top - 90) + 'px');
        }
    }
}


window.rcmail && rcmail.addEventListener('init', function(evt) {
    var ACTION_CONFIG = 'plugin.zpushconfig';

    // add button to tabs list
    var tab = $('<span>').attr('id', 'settingstabpluginzpushconfig').addClass('tablink');
    var button = $('<a>').attr('href', rcmail.env.comm_path+'&_action=plugin.zpushconfig').html(rcmail.gettext('tabtitle', 'kolab_zpush')).appendTo(tab);
    rcmail.add_element(tab, 'tabs');

    if (rcmail.env.action == ACTION_CONFIG)
        new kolab_zpush_config();
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

