/**
 * Client scripts for the Tasklist plugin
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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
 
function rcube_tasklist(settings)
{
    /*  constants  */
    var FILTER_MASK_ALL = 0;
    var FILTER_MASK_TODAY = 1;
    var FILTER_MASK_TOMORROW = 2;
    var FILTER_MASK_WEEK = 4;
    var FILTER_MASK_LATER = 8;
    var FILTER_MASK_NODATE = 16;
    var FILTER_MASK_OVERDUE = 32;
    var FILTER_MASK_FLAGGED = 64;
    var FILTER_MASK_COMPLETE = 128;
    
    var filter_masks = {
        all:      FILTER_MASK_ALL,
        today:    FILTER_MASK_TODAY,
        tomorrow: FILTER_MASK_TOMORROW,
        week:     FILTER_MASK_WEEK,
        later:    FILTER_MASK_LATER,
        nodate:   FILTER_MASK_NODATE,
        overdue:  FILTER_MASK_OVERDUE,
        flagged:  FILTER_MASK_FLAGGED,
        complete: FILTER_MASK_COMPLETE
    };
    
    /*  private vars  */
    var selector = 'all';
    var filtermask = FILTER_MASK_ALL;
    var loadstate = { filter:-1, lists:'' };
    var idcount = 0;
    var saving_lock;
    var ui_loading;
    var taskcounts = {};
    var listdata = {};
    var tags = [];
    var draghelper;
    var completeness_slider;
    var search_request;
    var search_query;
    var me = this;
    
    // general datepicker settings
    var datepicker_settings = {
      // translate from PHP format to datepicker format
      dateFormat: settings['date_format'].replace(/m/, 'mm').replace(/n/g, 'm').replace(/F/, 'MM').replace(/l/, 'DD').replace(/dd/, 'D').replace(/d/, 'dd').replace(/j/, 'd').replace(/Y/g, 'yy'),
      firstDay : settings['first_day'],
//      dayNamesMin: settings['days_short'],
//      monthNames: settings['months'],
//      monthNamesShort: settings['months'],
      changeMonth: false,
      showOtherMonths: true,
      selectOtherMonths: true
    };
    var extended_datepicker_settings;

    /*  public members  */
    this.tasklists = rcmail.env.tasklists;
    this.selected_task;
    this.selected_list;

    /*  public methods  */
    this.init = init;
    this.edit_task = task_edit_dialog;
    this.delete_task = delete_task;
    this.add_childtask = add_childtask;
    this.quicksearch = quicksearch;
    this.reset_search = reset_search;
    this.list_remove = list_remove;
    this.list_edit_dialog = list_edit_dialog;


    /**
     * initialize the tasks UI
     */
    function init()
    {
        // sinitialize task list selectors
        for (var id in me.tasklists) {
            if ((li = rcmail.get_folder_li(id, 'rcmlitasklist'))) {
                init_tasklist_li(li, id);
            }

            if (!me.tasklists.readonly && !me.selected_list) {
                me.selected_list = id;
                rcmail.enable_command('addtask', true);
                $(li).click();
            }
        }

        // register server callbacks
        rcmail.addEventListener('plugin.data_ready', data_ready);
        rcmail.addEventListener('plugin.refresh_task', update_taskitem);
        rcmail.addEventListener('plugin.update_counts', update_counts);
        rcmail.addEventListener('plugin.insert_tasklist', insert_list);
        rcmail.addEventListener('plugin.update_tasklist', update_list);
        rcmail.addEventListener('plugin.reload_data', function(){ list_tasks(null); });
        rcmail.addEventListener('plugin.unlock_saving', function(p){ rcmail.set_busy(false, null, saving_lock); });

        // start loading tasks
        fetch_counts();
        list_tasks();

        // register event handlers for UI elements
        $('#taskselector a').click(function(e){
            if (!$(this).parent().hasClass('inactive'))
                list_tasks(this.href.replace(/^.*#/, ''));
            return false;
        });

        // quick-add a task
        $(rcmail.gui_objects.quickaddform).submit(function(e){
            var tasktext = this.elements.text.value;
            var rec = { id:-(++idcount), title:tasktext, readonly:true, mask:0, complete:0 };

            save_task({ tempid:rec.id, raw:tasktext, list:me.selected_list }, 'new');
            render_task(rec);

            // clear form
            this.reset();
            return false;
        });

        // click-handler on tags list
        $(rcmail.gui_objects.tagslist).click(function(e){
            if (e.target.nodeName != 'LI')
                return;

            var item = $(e.target),
                tag = item.data('value');

            alert(tag);
        });

        // click-handler on task list items (delegate)
        $(rcmail.gui_objects.resultlist).click(function(e){
            var item = $(e.target);

            if (!item.hasClass('taskhead'))
                item = item.closest('div.taskhead');

            // ignore
            if (!item.length)
                return;

            var id = item.data('id'),
                li = item.parent(),
                rec = listdata[id];
            
            switch (e.target.className) {
                case 'complete':
                    rec.complete = e.target.checked ? 1 : 0;
                    li.toggleClass('complete');
                    save_task(rec, 'edit');
                    return true;
                
                case 'flagged':
                    rec.flagged = rec.flagged ? 0 : 1;
                    li.toggleClass('flagged');
                    save_task(rec, 'edit');
                    break;
                
                case 'date':
                    var link = $(e.target).html(''),
                        input = $('<input type="text" size="10" />').appendTo(link).val(rec.date || '')

                    input.datepicker($.extend({
                        onClose: function(dateText, inst) {
                            if (dateText != rec.date) {
                                rec.date = dateText;
                                save_task(rec, 'edit');
                            }
                            input.datepicker('destroy').remove();
                            link.html(dateText || rcmail.gettext('nodate','tasklist'));
                        },
                      }, extended_datepicker_settings)
                    )
                    .datepicker('setDate', rec.date)
                    .datepicker('show');
                    break;
                
                case 'delete':
                    delete_task(id);
                    break;

                case 'actions':
                    var pos, ref = $(e.target),
                        menu = $('#taskitemmenu');
                    if (menu.is(':visible') && menu.data('refid') == id) {
                        menu.hide();
                    }
                    else {
                        pos = ref.offset();
                        pos.top += ref.outerHeight();
                        pos.left += ref.width() - menu.outerWidth();
                        menu.css({ top:pos.top+'px', left:pos.left+'px' }).show();
                        menu.data('refid', id);
                        me.selected_task = rec;
                    }
                    e.bubble = false;
                    break;
                
                default:
                    if (e.target.nodeName != 'INPUT')
                        task_show_dialog(id);
                    break;
            }

            return false;
        })
        .dblclick(function(e){
            var id, rec, item = $(e.target);
            if (!item.hasClass('taskhead'))
                item = item.closest('div.taskhead');

            if (item.length && (id = item.data('id')) && (rec = listdata[id])) {
                var list = rec.list && me.tasklists[rec.list] ? me.tasklists[rec.list] : {};
                if (rec.readonly || list.readonly)
                    task_show_dialog(id);
                else
                    task_edit_dialog(id, 'edit');
                clearSelection();
            }
        });

        completeness_slider = $('#edit-completeness-slider').slider({
            range: 'min',
            slide: function(e, ui){
                var v = completeness_slider.slider('value');
                if (v >= 98) v = 100;
                if (v <= 2)  v = 0;
                $('#edit-completeness').val(v);
            }
        });
        $('#edit-completeness').change(function(e){ completeness_slider.slider('value', parseInt(this.value)) });

        // handle global document clicks: close popup menus
        $(document.body).click(clear_popups);

        // extended datepicker settings
        extended_datepicker_settings = $.extend({
            showButtonPanel: true,
            beforeShow: function(input, inst) {
                setTimeout(function(){
                    $(input).datepicker('widget').find('button.ui-datepicker-close')
                        .html(rcmail.gettext('nodate','tasklist'))
                        .attr('onclick', '')
                        .click(function(e){
                            $(input).datepicker('setDate', null).datepicker('hide');
                        });
                }, 1);
            },
        }, datepicker_settings);
    }

    /**
     * Request counts from the server
     */
    function fetch_counts()
    {
        var active = active_lists();
        if (active.length)
            rcmail.http_request('counts', { lists:active.join(',') });
        else
            update_counts({});
    }

    /**
     * List tasks matching the given selector
     */
    function list_tasks(sel)
    {
        if (rcmail.busy)
            return;

        if (sel && filter_masks[sel] !== undefined) {
            filtermask = filter_masks[sel];
            selector = sel;
        }

        var active = active_lists(),
            basefilter = filtermask == FILTER_MASK_COMPLETE ? FILTER_MASK_COMPLETE : FILTER_MASK_ALL,
            reload = active.join(',') != loadstate.lists || basefilter != loadstate.filter;

        if (active.length && reload) {
            ui_loading = rcmail.set_busy(true, 'loading');
            rcmail.http_request('fetch', { filter:basefilter, lists:active.join(','), q:search_query }, true);
        }
        else if (reload)
            data_ready([]);
        else
            render_tasklist();

        $('#taskselector li.selected').removeClass('selected');
        $('#taskselector li.'+selector).addClass('selected');
    }

    /**
     * Callback if task data from server is ready
     */
    function data_ready(response)
    {
        listdata = {};
        loadstate.lists = response.lists;
        loadstate.filter = response.filter;
        for (var i=0; i < response.data.length; i++) {
            listdata[response.data[i].id] = response.data[i];
        }

        // find new tags
        var newtags = [];
        for (var i=0; i < response.tags.length; i++) {
            if (tags.indexOf(response.tags[i]) < 0)
                newtags.push(response.tags[i]);
        }
        tags = tags.concat(newtags);

        // append new tags to tag cloud
        var taglist = $(rcmail.gui_objects.tagslist);
        $.each(newtags, function(i, tag){
            $('<li>').attr('rel', tag).data('value', tag).html(Q(tag)).appendTo(taglist);
        });

        render_tasklist();
        rcmail.set_busy(false, 'loading', ui_loading);
    }

    /**
     *
     */
    function render_tasklist()
    {
        // clear display
        var rec,
            count = 0,
            msgbox = $('#listmessagebox').hide(),
            list = $(rcmail.gui_objects.resultlist).html('');

        for (var id in listdata) {
            rec = listdata[id];
            if (match_filter(rec)) {
                render_task(rec);
                count++;
            }
        }

        if (!count)
            msgbox.html(rcmail.gettext('notasksfound','tasklist')).show();
    }

    /**
     *
     */
    function update_counts(counts)
    {
        // got new data
        if (counts)
            taskcounts = counts;

        // iterate over all selector links and update counts
        $('#taskselector a').each(function(i, elem){
            var link = $(elem),
                f = link.parent().attr('class').replace(/\s\w+/, '');
            link.children('span').html(taskcounts[f] || '')[(taskcounts[f] ? 'show' : 'hide')]();
        });

        // spacial case: overdue
        $('#taskselector li.overdue')[(taskcounts.overdue ? 'removeClass' : 'addClass')]('inactive');
    }

    /**
     * Callback from server to update a single task item
     */
    function update_taskitem(rec)
    {
        var id = rec.id;
        listdata[id] = rec;
        render_task(rec, rec.tempid || id);
    }

    /**
     * Submit the given (changed) task record to the server
     */
    function save_task(rec, action)
    {
        if (!rcmail.busy) {
            saving_lock = rcmail.set_busy(true, 'tasklist.savingdata');
            rcmail.http_post('task', { action:action, t:rec, filter:filtermask });
            return true;
        }
        
        return false;
    }

    /**
     * Render the given task into the tasks list
     */
    function render_task(rec, replace)
    {
        var div = $('<div>').addClass('taskhead').html(
            '<div class="progressbar"><div class="progressvalue" style="width:' + (rec.complete * 100) + '%"></div></div>' +
            '<input type="checkbox" name="completed[]" value="1" class="complete" ' + (rec.complete == 1.0 ? 'checked="checked" ' : '') + '/>' + 
            '<span class="flagged"></span>' +
            '<span class="title">' + Q(rec.title) + '</span>' +
            '<span class="date">' + Q(rec.date || rcmail.gettext('nodate','tasklist')) + '</span>' +
            '<a href="#" class="actions">V</a>'
            )
            .data('id', rec.id)
            .draggable({
                revert: 'invalid',
                addClasses: false,
                cursorAt: { left:-10, top:12 },
                helper: draggable_helper,
                appendTo: 'body',
                start: draggable_start,
                stop: draggable_stop,
                revertDuration: 300
            });

        if (rec.complete == 1.0)
            div.addClass('complete');
        if (rec.flagged)
            div.addClass('flagged');
        if (!rec.date)
            div.addClass('nodate');
        if ((rec.mask & FILTER_MASK_OVERDUE))
            div.addClass('overdue');

        var li, parent;
        if (replace && (li = $('li[rel="'+replace+'"]', rcmail.gui_objects.resultlist)) && li.length) {
            li.children('div.taskhead').first().replaceWith(div);
            li.attr('rel', rec.id);
        }
        else {
            li = $('<li>')
                .attr('rel', rec.id)
                .addClass('taskitem')
                .append(div)
                .append('<ul class="childtasks"></ul>');

            if (rec.parent_id && (parent = $('li[rel="'+rec.parent_id+'"] > ul.childtasks', rcmail.gui_objects.resultlist)) && parent.length)
                li.appendTo(parent);
            else
                li.appendTo(rcmail.gui_objects.resultlist);
        }
        
        if (replace) {
            resort_task(rec, li, true);
            // TODO: remove the item after a while if it doesn't match the current filter anymore
        }
    }

    /**
     * Move the given task item to the right place in the list
     */
    function resort_task(rec, li, animated)
    {
        var dir = 0, next_li, next_id, next_rec;

        // animated moving
        var insert_animated = function(li, before, after) {
            if (before && li.next().get(0) == before.get(0))
                return; // nothing to do
            else if (after && li.prev().get(0) == after.get(0))
                return; // nothing to do
            
            var speed = 300;
            li.slideUp(speed, function(){
                if (before)     li.insertBefore(before);
                else if (after) li.insertAfter(after);
                li.slideDown(speed);
            });
        }

        // find the right place to insert the task item
        li.siblings().each(function(i, elem){
            next_li = $(elem);
            next_id = next_li.attr('rel');
            next_rec = listdata[next_id];

            if (next_id == rec.id) {
                next_li = null;
                return 1; // continue
            }

            if (next_rec && task_cmp(rec, next_rec) > 0) {
                return 1; // continue;
            }
            else if (next_rec && next_li && task_cmp(rec, next_rec) < 0) {
                if (animated) insert_animated(li, next_li);
                else          li.insertBefore(next_li)
                next_li = null;
                return false;
            }
        });

        if (next_li) {
            if (animated) insert_animated(li, null, next_li);
            else          li.insertAfter(next_li);
        }
        return;
    }

    /**
     * Compare function of two task records.
     * (used for sorting)
     */
    function task_cmp(a, b)
    {
        var d = Math.floor(a.complete) - Math.floor(b.complete);
        if (!d) d = (b._hasdate-0) - (a._hasdate-0);
        if (!d) d = (a.datetime||99999999999) - (b.datetime||99999999999);
        return d;
    }


    /*  Helper functions for drag & drop functionality  */
    
    function draggable_helper()
    {
        if (!draghelper)
            draghelper = $('<div class="taskitem-draghelper">&#x2714;</div>');

        return draghelper;
    }

    function draggable_start(event, ui)
    {
        $('.taskhead, #rootdroppable').droppable({
            hoverClass: 'droptarget',
            accept: droppable_accept,
            drop: draggable_dropped,
            addClasses: false
        });

        $(this).parent().addClass('dragging');
        $('#rootdroppable').show();
    }

    function draggable_stop(event, ui)
    {
        $(this).parent().removeClass('dragging');
        $('#rootdroppable').hide();
    }

    function droppable_accept(draggable)
    {
        var drag_id = draggable.data('id'),
            parent_id = $(this).data('id'),
            rec = listdata[parent_id];

        if (parent_id == listdata[drag_id].parent_id)
            return false;

        while (rec && rec.parent_id) {
            if (rec.parent_id == drag_id)
                return false;
            rec = listdata[rec.parent_id];
        }

        return true;
    }

    function draggable_dropped(event, ui)
    {
        var parent_id = $(this).data('id'),
            task_id = ui.draggable.data('id'),
            parent = parent_id ? $('li[rel="'+parent_id+'"] > ul.childtasks', rcmail.gui_objects.resultlist) : $(rcmail.gui_objects.resultlist),
            rec = listdata[task_id],
            li;

        if (rec && parent.length) {
            // submit changes to server
            rec.parent_id = parent_id || 0;
            save_task(rec, 'edit');

            li = ui.draggable.parent();
            li.slideUp(300, function(){
                li.appendTo(parent);
                resort_task(rec, li);
                li.slideDown(300);
            });
        }
    }


    /**
     * Show task details in a dialog
     */
    function task_show_dialog(id)
    {
        var $dialog = $('#taskshow').dialog('close'), rec;;

        if (!(rec = listdata[id]) || clear_popups({}))
            return;

        me.selected_task = rec;

        // fill dialog data
        $('#task-parent-title').html(Q(rec.parent_title || '')+' &raquo;').css('display', rec.parent_title ? 'block' : 'none');
        $('#task-title').html(Q(rec.title || ''));
        $('#task-description').html(text2html(rec.description || '', 300, 6))[(rec.description ? 'show' : 'hide')]();
        $('#task-date')[(rec.date ? 'show' : 'hide')]().children('.task-text').html(Q(rec.date || rcmail.gettext('nodate','tasklist')));
        $('#task-time').html(Q(rec.time || ''));
        $('#task-completeness .task-text').html(((rec.complete || 0) * 100) + '%');
        $('#task-list .task-text').html(Q(me.tasklists[rec.list] ? me.tasklists[rec.list].name : ''));

        // define dialog buttons
        var buttons = {};
        buttons[rcmail.gettext('edit','tasklist')] = function() {
            task_edit_dialog(me.selected_task.id, 'edit');
            $dialog.dialog('close');
        };

        buttons[rcmail.gettext('delete','tasklist')] = function() {
            if (delete_task(me.selected_task.id))
                $dialog.dialog('close');
        };

        // open jquery UI dialog
        $dialog.dialog({
          modal: false,
          resizable: true,
          closeOnEscape: true,
          title: rcmail.gettext('taskdetails', 'tasklist'),
          close: function() {
            $dialog.dialog('destroy').appendTo(document.body);
          },
          buttons: buttons,
          minWidth: 500,
          width: 580
        }).show();
    }

    /**
     * Opens the dialog to edit a task
     */
    function task_edit_dialog(id, action, presets)
    {
        $('#taskshow').dialog('close');

        var rec = listdata[id] || presets,
            $dialog = $('<div>'),
            editform = $('#taskedit'),
            list = rec.list && me.tasklists[rec.list] ? me.tasklists[rec.list] :
                (me.selected_list ? me.tasklists[me.selected_list] : { editable: action=='new' });

        if (list.readonly || (action == 'edit' && (!rec || rec.readonly || rec.temp)))
            return false;

        me.selected_task = $.extend({}, rec);  // clone task object

        // fill form data
        var title = $('#edit-title').val(rec.title || '');
        var description = $('#edit-description').val(rec.description || '');
        var recdate = $('#edit-date').val(rec.date || '').datepicker(datepicker_settings);
        var rectime = $('#edit-time').val(rec.time || '');
        var complete = $('#edit-completeness').val((rec.complete || 0) * 100);
        completeness_slider.slider('value', complete.val());
        var tasklist = $('#edit-tasklist').val(rec.list || 0); // .prop('disabled', rec.parent_id ? true : false);

        $('#edit-nodate').unbind('click').click(function(){
            recdate.val('');
            rectime.val('');
            return false;
        })

        // define dialog buttons
        var buttons = {};
        buttons[rcmail.gettext('save', 'tasklist')] = function() {
            me.selected_task.title = title.val();
            me.selected_task.description = description.val();
            me.selected_task.date = recdate.val();
            me.selected_task.time = rectime.val();
            me.selected_task.list = tasklist.val();

            if (me.selected_task.list && me.selected_task.list != rec.list)
              me.selected_task._fromlist = rec.list;

            me.selected_task.complete = complete.val() / 100;
            if (isNaN(me.selected_task.complete))
                me.selected_task.complete = null;

            if (!me.selected_task.list && list.id)
                me.selected_task.list = list.id;

            if (save_task(me.selected_task, action))
                $dialog.dialog('close');
        };

        if (rec.id) {
          buttons[rcmail.gettext('delete', 'tasklist')] = function() {
            if (delete_task(rec.id))
                $dialog.dialog('close');
          };
        }

        buttons[rcmail.gettext('cancel', 'tasklist')] = function() {
          $dialog.dialog('close');
        };

        // open jquery UI dialog
        $dialog.dialog({
          modal: true,
          resizable: (!bw.ie6 && !bw.ie7),  // disable for performance reasons
          closeOnEscape: false,
          title: rcmail.gettext((action == 'edit' ? 'edittask' : 'newtask'), 'tasklist'),
          close: function() {
            editform.hide().appendTo(document.body);
            $dialog.dialog('destroy').remove();
          },
          buttons: buttons,
          minWidth: 500,
          width: 580
        }).append(editform.show());  // adding form content AFTERWARDS massively speeds up opening on IE6

        title.select();
    }

    /**
     *
     */
    function add_childtask(id)
    {
        task_edit_dialog(null, 'new', { parent_id:id });
    }

    /**
     * Delete the given task
     */
    function delete_task(id)
    {
        var rec = listdata[id];
        if (rec && confirm("Delete this?")) {
            saving_lock = rcmail.set_busy(true, 'tasklist.savingdata');
            rcmail.http_post('task', { action:'delete', t:rec, filter:filtermask });
            $('li[rel="'+id+'"]', rcmail.gui_objects.resultlist).hide();
            return true;
        }
        
        return false;
    }

    /**
     * Check if the given task matches the current filtermask
     */
    function match_filter(rec)
    {
        return !filtermask || (filtermask & rec.mask) > 0;
    }

    /**
     *
     */
    function list_edit_dialog(id)
    {
        var list = me.tasklists[id],
            $dialog = $('#tasklistform').dialog('close');
            editform = $('#tasklisteditform');

        if (!list)
            list = { name:'', editable:true, showalarms:true };

        // fill edit form
        var name = $('#edit-tasklistame').prop('disabled', !list.editable).val(list.editname || list.name),
            alarms = $('#edit-showalarms').prop('checked', list.showalarms).get(0),
            parent = $('#edit-parentfolder').val(list.parentfolder);

        // dialog buttons
        var buttons = {};

        buttons[rcmail.gettext('save','tasklist')] = function() {
          // do some input validation
          if (!name.val() || name.val().length < 2) {
            alert(rcmail.gettext('invalidlistproperties', 'tasklist'));
            name.select();
            return;
          }

          // post data to server
          var data = editform.serializeJSON();
          if (list.id)
            data.id = list.id;
          if (alarms)
            data.showalarms = alarms.checked ? 1 : 0;
        if (parent.length)
            data.parentfolder = $('option:selected', parent).val();

          saving_lock = rcmail.set_busy(true, 'tasklist.savingdata');
          rcmail.http_post('tasklist', { action:(list.id ? 'edit' : 'new'), l:data });
          $dialog.dialog('close');
        };

        buttons[rcmail.gettext('cancel','tasklist')] = function() {
          $dialog.dialog('close');
        };

        // open jquery UI dialog
        $dialog.dialog({
          modal: true,
          resizable: true,
          closeOnEscape: false,
          title: rcmail.gettext((list.id ? 'editlist' : 'createlist'), 'tasklist'),
          close: function() { $dialog.dialog('destroy').hide(); },
          buttons: buttons,
          minWidth: 400,
          width: 420
        }).show();
    }

    /**
     *
     */
    function list_remove(id)
    {
        var list = me.tasklists[id];
        if (list && !list.readonly) {
            alert('To be implemented')
        }
    }

    /**
     *
     */
    function insert_list(prop)
    {
        console.log(prop)
        var li = $('<li>').attr('id', 'rcmlitasklist'+prop.id)
            .append('<input type="checkbox" name="_list[]" value="'+prop.id+'" checked="checked" />')
            .append('<span class="handle">&nbsp;</span>')
            .append('<span class="listname">'+Q(prop.name)+'</span>');
        $(rcmail.gui_objects.folderlist).append(li);
        init_tasklist_li(li.get(0), prop.id);
        me.tasklists[prop.id] = prop;
    }

    /**
     *
     */
    function update_list(prop)
    {
        var id = prop.oldid || prop.id,
            li = rcmail.get_folder_li(id, 'rcmlitasklist');

        if (me.tasklists[id] && li) {
            delete me.tasklists[id];
            me.tasklists[prop.id] = prop;
            $(li).data('id', prop.id);
            $('#'+li.id+' input').data('id', prop.id);
            $('.listname', li).html(Q(prop.name));
        }
    }

    /**
     * Execute search
     */
    function quicksearch()
    {
        var q;
        if (rcmail.gui_objects.qsearchbox && (q = rcmail.gui_objects.qsearchbox.value)) {
            var id = 'search-'+q;
            var resources = [];

            for (var rid in me.tasklists) {
                if (me.tasklists[rid].active) {
                    resources.push(rid);
                }
            }
            id += '@'+resources.join(',');

            // ignore if query didn't change
            if (search_request == id)
                return;

            search_request = id;
            search_query = q;

            list_tasks('all');
        }
        else  // empty search input equals reset
            this.reset_search();
    }

    /**
     * Reset search and get back to normal listing
     */
    function reset_search()
    {
        $(rcmail.gui_objects.qsearchbox).val('');

        if (search_request) {
            search_request = search_query = null;
            list_tasks();
        }
    }


    /**** Utility functions ****/

    /**
     * quote html entities
     */
    function Q(str)
    {
      return String(str).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * Name says it all
     * (cloned from calendar plugin)
     */
    function text2html(str, maxlen, maxlines)
    {
      var html = Q(String(str));

      // limit visible text length
      if (maxlen) {
        var morelink = ' <a href="#more" onclick="$(this).hide().next().show();return false" class="morelink">'+rcmail.gettext('showmore','tasklist')+'</a><span style="display:none">',
          lines = html.split(/\r?\n/),
          words, out = '', len = 0;

        for (var i=0; i < lines.length; i++) {
          len += lines[i].length;
          if (maxlines && i == maxlines - 1) {
            out += lines[i] + '\n' + morelink;
            maxlen = html.length * 2;
          }
          else if (len > maxlen) {
            len = out.length;
            words = lines[i].split(' ');
            for (var j=0; j < words.length; j++) {
              len += words[j].length + 1;
              out += words[j] + ' ';
              if (len > maxlen) {
                out += morelink;
                maxlen = html.length * 2;
              }
            }
            out += '\n';
          }
          else
            out += lines[i] + '\n';
        }

        if (maxlen > str.length)
          out += '</span>';

        html = out;
      }
      
      // simple link parser (similar to rcube_string_replacer class in PHP)
      var utf_domain = '[^?&@"\'/\\(\\)\\s\\r\\t\\n]+\\.([^\x00-\x2f\x3b-\x40\x5b-\x60\x7b-\x7f]{2,}|xn--[a-z0-9]{2,})';
      var url1 = '.:;,', url2 = 'a-z0-9%=#@+?&/_~\\[\\]-';
      var link_pattern = new RegExp('([hf]t+ps?://)('+utf_domain+'(['+url1+']?['+url2+']+)*)?', 'ig');
      var mailto_pattern = new RegExp('([^\\s\\n\\(\\);]+@'+utf_domain+')', 'ig');

      return html
        .replace(link_pattern, '<a href="$1$2" target="_blank">$1$2</a>')
        .replace(mailto_pattern, '<a href="mailto:$1">$1</a>')
        .replace(/(mailto:)([^"]+)"/g, '$1$2" onclick="rcmail.command(\'compose\', \'$2\');return false"')
        .replace(/\n/g, "<br/>");
    }

    /**
     * Clear any text selection
     * (text is probably selected when double-clicking somewhere)
     */
    function clearSelection()
    {
        if (document.selection && document.selection.empty) {
            document.selection.empty() ;
        }
        else if (window.getSelection) {
            var sel = window.getSelection();
            if (sel && sel.removeAllRanges)
                sel.removeAllRanges();
        }
    }

    /**
     * Hide all open popup menus
     */
    function clear_popups(e)
    {
        var count = 0, target = e.target;
        if (target && target.className == 'inner')
            target = e.target.parentNode;

        $('.popupmenu:visible').each(function(i, elem){
            var menu = $(elem), id = elem.id;
            if (target.id != id+'link' && (!menu.data('sticky') || !target_overlaps(e.target, elem))) {
                menu.hide();
                count++;
            }
        });
        return count;
    }

    /**
     * Check whether the event target is a descentand of the given element
     */
    function target_overlaps(target, elem)
    {
        while (target.parentNode) {
            if (target.parentNode == elem)
                return true;
            target = target.parentNode;
        }
        return false;
    }

    /**
     *
     */
    function active_lists()
    {
        var active = [];
        for (var id in me.tasklists) {
            if (me.tasklists[id].active)
                active.push(id);
        }
        return active;
    }

    /**
     * Register event handlers on a tasklist (folder) item
     */
    function init_tasklist_li(li, id)
    {
        $('#'+li.id+' input').click(function(e){
            var id = $(this).data('id');
            if (me.tasklists[id]) {  // add or remove event source on click
                me.tasklists[id].active = this.checked;
                fetch_counts();
                list_tasks(null);
                rcmail.http_post('tasklist', { action:'subscribe', l:{ id:id, active:me.tasklists[id].active?1:0 } });
            }
        }).data('id', id).get(0).checked = me.tasklists[id].active || false;

        $(li).click(function(e){
            var id = $(this).data('id');
            rcmail.select_folder(id, 'rcmlitasklist');
            rcmail.enable_command('list-edit', 'list-remove', 'import', !me.tasklists[id].readonly);
            me.selected_list = id;
      })
//              .dblclick(function(){ list_edit_dialog(me.selected_list); })
      .data('id', id);
    }
}


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


/* tasklist plugin UI initialization */
var rctasks;
window.rcmail && rcmail.addEventListener('init', function(evt) {

  rctasks = new rcube_tasklist(rcmail.env.tasklist_settings);

  // register button commands
  //rcmail.register_command('addtask', function(){ rctasks.add_task(); }, true);
  //rcmail.register_command('print', function(){ rctasks.print_list(); }, true);

  rcmail.register_command('list-create', function(){ rctasks.list_edit_dialog(null); }, true);
  rcmail.register_command('list-edit', function(){ rctasks.list_edit_dialog(rctasks.selected_list); }, false);
  rcmail.register_command('list-remove', function(){ rctasks.list_remove(rctasks.selected_list); }, false);

  rcmail.register_command('search', function(){ rctasks.quicksearch(); }, true);
  rcmail.register_command('reset-search', function(){ rctasks.reset_search(); }, true);

  rctasks.init();
});
