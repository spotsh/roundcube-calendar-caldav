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
 
function rcube_tasklist_ui(settings)
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
    var tagsfilter = [];
    var filtermask = FILTER_MASK_ALL;
    var loadstate = { filter:-1, lists:'', search:null };
    var idcount = 0;
    var saving_lock;
    var ui_loading;
    var taskcounts = {};
    var listindex = [];
    var listdata = {};
    var tags = [];
    var draghelper;
    var search_request;
    var search_query;
    var completeness_slider;
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
    this.unlock_saving = unlock_saving;


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
        rcmail.addEventListener('plugin.unlock_saving', unlock_saving);

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
                return false;

            var item = $(e.target),
                tag = item.data('value');

            // reset selection on regular clicks
            var index = tagsfilter.indexOf(tag);
            var shift = e.shiftKey || e.ctrlKey || e.metaKey;

            if (!shift) {
                if (tagsfilter.length > 1)
                    index = -1;

                $('li', this).removeClass('selected');
                tagsfilter = [];
            }

            // add tag to filter
            if (index < 0) {
                item.addClass('selected');
                tagsfilter.push(tag);
            }
            else if (shift) {
                item.removeClass('selected');
                var a = tagsfilter.slice(0,index);
                tagsfilter = a.concat(tagsfilter.slice(index+1));
            }

            list_tasks();

            e.preventDefault();
            return false;
        })
        .mousedown(function(e){
            // disable content selection with the mouse
            e.preventDefault();
            return false;
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

        // handle global document clicks: close popup menus
        $(document.body).click(clear_popups);

        // extended datepicker settings
        var extended_datepicker_settings = $.extend({
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
     * initialize task edit form elements
     */
    function init_taskedit()
    {
        $('#taskedit').tabs();

        completeness_slider = $('#taskedit-completeness-slider').slider({
            range: 'min',
            slide: function(e, ui){
                var v = completeness_slider.slider('value');
                if (v >= 98) v = 100;
                if (v <= 2)  v = 0;
                $('#taskedit-completeness').val(v);
            }
        });
        $('#taskedit-completeness').change(function(e){
            completeness_slider.slider('value', parseInt(this.value))
        });

        // register events on alarm fields
        $('#taskedit select.edit-alarm-type').change(function(){
            $(this).parent().find('span.edit-alarm-values')[(this.selectedIndex>0?'show':'hide')]();
        });
        $('#taskedit select.edit-alarm-offset').change(function(){
            var mode = $(this).val() == '@' ? 'show' : 'hide';
            $(this).parent().find('.edit-alarm-date, .edit-alarm-time')[mode]();
            $(this).parent().find('.edit-alarm-value').prop('disabled', mode == 'show');
        });

        $('#taskedit-date, #taskedit-startdate, #taskedit .edit-alarm-date').datepicker(datepicker_settings);

        $('a.edit-nodate').click(function(){
            var sel = $(this).attr('rel');
            if (sel) $(sel).val('');
            return false;
        });
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
            reload = active.join(',') != loadstate.lists || basefilter != loadstate.filter || loadstate.search != search_query;

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
        listindex = [];
        loadstate.lists = response.lists;
        loadstate.filter = response.filter;
        loadstate.search = response.search;

        for (var i=0; i < response.data.length; i++) {
            listdata[response.data[i].id] = response.data[i];
            listindex.push(response.data[i].id);
        }

        render_tasklist();
        append_tags(response.tags || []);
        rcmail.set_busy(false, 'loading', ui_loading);
    }

    /**
     *
     */
    function render_tasklist()
    {
        // clear display
        var id, rec,
            count = 0,
            msgbox = $('#listmessagebox').hide(),
            list = $(rcmail.gui_objects.resultlist).html('');

        for (var i=0; i < listindex.length; i++) {
            id = listindex[i];
            rec = listdata[id];
            if (match_filter(rec)) {
                render_task(rec);
                count++;
            }
        }

        if (!count)
            msgbox.html(rcmail.gettext('notasksfound','tasklist')).show();
    }

    function append_tags(taglist)
    {
        // find new tags
        var newtags = [];
        for (var i=0; i < taglist.length; i++) {
            if (tags.indexOf(taglist[i]) < 0)
                newtags.push(taglist[i]);
        }
        tags = tags.concat(newtags);

        // append new tags to tag cloud
        $.each(newtags, function(i, tag){
            $('<li>').attr('rel', tag).data('value', tag).html(Q(tag)).appendTo(rcmail.gui_objects.tagslist);
        });

        // re-sort tags list
        $(rcmail.gui_objects.tagslist).children('li').sortElements(function(a,b){
            return $.text([a]).toLowerCase() > $.text([b]).toLowerCase() ? 1 : -1;
        });
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
        var id = rec.id,
            oldid = rec.tempid || id;
            oldindex = listindex.indexOf(oldid);

        if (oldindex >= 0)
            listindex[oldindex] = id;
        else
            listindex.push(id);

        listdata[id] = rec;

        render_task(rec, oldid);
        append_tags(rec.tags || []);
    }

    /**
     * Submit the given (changed) task record to the server
     */
    function save_task(rec, action)
    {
        if (!rcmail.busy) {
            saving_lock = rcmail.set_busy(true, 'tasklist.savingdata');
            rcmail.http_post('tasks/task', { action:action, t:rec, filter:filtermask });
            return true;
        }
        
        return false;
    }

    /**
     * Remove saving lock and free the UI for new input
     */
    function unlock_saving()
    {
        if (saving_lock)
            rcmail.set_busy(false, null, saving_lock);
    }

    /**
     * Render the given task into the tasks list
     */
    function render_task(rec, replace)
    {
        var tags_html = '';
        for (var j=0; rec.tags && j < rec.tags.length; j++)
            tags_html += '<span class="tag">' + Q(rec.tags[j]) + '</span>';

        var div = $('<div>').addClass('taskhead').html(
            '<div class="progressbar"><div class="progressvalue" style="width:' + (rec.complete * 100) + '%"></div></div>' +
            '<input type="checkbox" name="completed[]" value="1" class="complete" ' + (rec.complete == 1.0 ? 'checked="checked" ' : '') + '/>' + 
            '<span class="flagged"></span>' +
            '<span class="title">' + Q(rec.title) + '</span>' +
            '<span class="tags">' + tags_html + '</span>' +
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

        var li, parent = rec.parent_id ? $('li[rel="'+rec.parent_id+'"] > ul.childtasks', rcmail.gui_objects.resultlist) : null;
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

            if (!parent || !parent.length)
                li.appendTo(rcmail.gui_objects.resultlist);
        }

        if (parent && parent.length)
            li.appendTo(parent);

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
        var dir = 0, index, slice, next_li, next_id, next_rec;

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

        // remove from list index
        var oldlist = listindex.join('%%%');
        var oldindex = listindex.indexOf(rec.id);
        if (oldindex >= 0) {
            slice = listindex.slice(0,oldindex);
            listindex = slice.concat(listindex.slice(oldindex+1));
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
                else          li.insertBefore(next_li);
                next_li = null;
                return false;
            }
        });

        index = listindex.indexOf(next_id);

        if (next_li) {
            if (animated) insert_animated(li, null, next_li);
            else          li.insertAfter(next_li);
            index++;
        }

        // insert into list index
        if (next_id && index >= 0) {
            slice = listindex.slice(0,index);
            slice.push(rec.id);
            listindex = slice.concat(listindex.slice(index));
        }
        else {  // restore old list index
            listindex = oldlist.split('%%%');
        }
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
            drag_rec = listdata[drag_id],
            drop_rec = listdata[parent_id];

        if (drop_rec && drop_rec.list != drag_rec.list)
            return false;

        if (parent_id == drag_rec.parent_id)
            return false;

        while (drop_rec && drop_rec.parent_id) {
            if (drop_rec.parent_id == drag_id)
                return false;
            drop_rec = listdata[drop_rec.parent_id];
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
        $('#task-start')[(rec.startdate ? 'show' : 'hide')]().children('.task-text').html(Q(rec.startdate || ''));
        $('#task-starttime').html(Q(rec.starttime || ''));
        $('#task-alarm')[(rec.alarms_text ? 'show' : 'hide')]().children('.task-text').html(Q(rec.alarms_text));
        $('#task-completeness .task-text').html(((rec.complete || 0) * 100) + '%');
        $('#task-list .task-text').html(Q(me.tasklists[rec.list] ? me.tasklists[rec.list].name : ''));

        var taglist = $('#task-tags')[(rec.tags && rec.tags.length ? 'show' : 'hide')]().children('.task-text').empty();
        if (rec.tags && rec.tags.length) {
            $.each(rec.tags, function(i,val){
                $('<span>').addClass('tag-element').html(Q(val)).data('value', val).appendTo(taglist);
            });
        }

        // build attachments list
        $('#task-attachments').hide();
        if ($.isArray(rec.attachments)) {
            task_show_attachments(rec.attachments || [], $('#task-attachments').children('.task-text'), rec);
            if (rec.attachments.length > 0) {
                $('#task-attachments').show();
          }
        }

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

        if (list.readonly || (action == 'edit' && (!rec || rec.readonly)))
            return false;

        me.selected_task = $.extend({}, rec);  // clone task object

        // assign temporary id
        if (!me.selected_task.id)
            me.selected_task.id = -(++idcount);

        // reset dialog first
        $('#taskeditform').get(0).reset();

        // fill form data
        var title = $('#taskedit-title').val(rec.title || '');
        var description = $('#taskedit-description').val(rec.description || '');
        var recdate = $('#taskedit-date').val(rec.date || '');
        var rectime = $('#taskedit-time').val(rec.time || '');
        var recstartdate = $('#taskedit-startdate').val(rec.startdate || '');
        var recstarttime = $('#taskedit-starttime').val(rec.starttime || '');
        var complete = $('#taskedit-completeness').val((rec.complete || 0) * 100);
        completeness_slider.slider('value', complete.val());
        var tasklist = $('#taskedit-tasklist').val(rec.list || 0).prop('disabled', rec.parent_id ? true : false);

        // tag-edit line
        var tagline = $(rcmail.gui_objects.edittagline).empty();
        $.each(typeof rec.tags == 'object' && rec.tags.length ? rec.tags : [''], function(i,val){
            $('<input>')
                .attr('name', 'tags[]')
                .attr('tabindex', '3')
                .addClass('tag')
                .val(val)
                .appendTo(tagline);
        });

        $('input.tag', rcmail.gui_objects.edittagline).tagedit({
            animSpeed: 100,
            allowEdit: false,
            checkNewEntriesCaseSensitive: false,
            autocompleteOptions: { source: tags, minLength: 0 },
            texts: { removeLinkTitle: rcmail.gettext('removetag', 'tasklist') }
        });

        // set alarm(s)
        if (rec.alarms) {
            if (typeof rec.alarms == 'string')
                rec.alarms = rec.alarms.split(';');

          for (var alarm, i=0; i < rec.alarms.length; i++) {
              alarm = String(rec.alarms[i]).split(':');
              if (!alarm[1] && alarm[0]) alarm[1] = 'DISPLAY';
              $('#taskedit select.edit-alarm-type').val(alarm[1]);

              if (alarm[0].match(/@(\d+)/)) {
                  var ondate = fromunixtime(parseInt(RegExp.$1));
                  $('#taskedit select.edit-alarm-offset').val('@');
                  $('#taskedit input.edit-alarm-date').val(format_datetime(ondate, 1));
                  $('#taskedit input.edit-alarm-time').val(format_datetime(ondate, 2));
              }
              else if (alarm[0].match(/([-+])(\d+)([MHD])/)) {
                  $('#taskedit input.edit-alarm-value').val(RegExp.$2);
                  $('#taskedit select.edit-alarm-offset').val(''+RegExp.$1+RegExp.$3);
              }

              break; // only one alarm is currently supported
          }
        }
        // set correct visibility by triggering onchange handlers
        $('#taskedit select.edit-alarm-type, #taskedit select.edit-alarm-offset').change();

        // attachments
        rcmail.enable_command('remove-attachment', !list.readonly);
        me.selected_task.deleted_attachments = [];
        // we're sharing some code for uploads handling with app.js
        rcmail.env.attachments = [];
        rcmail.env.compose_id = me.selected_task.id; // for rcmail.async_upload_form()

        if ($.isArray(rec.attachments)) {
            task_show_attachments(rec.attachments, $('#taskedit-attachments'), rec, true);
        }
        else {
            $('#taskedit-attachments > ul').empty();
        }

        // show/hide tabs according to calendar's feature support
        $('#taskedit-tab-attachments')[(list.attachments||rec.attachments?'show':'hide')]();

        // activate the first tab
        $('#taskedit').tabs('select', 0);

        // define dialog buttons
        var buttons = {};
        buttons[rcmail.gettext('save', 'tasklist')] = function() {
            // copy form field contents into task object to save
            $.each({ title:title, description:description, date:recdate, time:rectime, startdate:recstartdate, starttime:recstarttime, list:tasklist }, function(key,input){
                me.selected_task[key] = input.val();
            });
            me.selected_task.tags = [];
            me.selected_task.attachments = [];

            // do some basic input validation
            if (me.selected_task.startdate && me.selected_task.date) {
                var startdate = $.datepicker.parseDate(datepicker_settings.dateFormat, me.selected_task.startdate, datepicker_settings);
                var duedate = $.datepicker.parseDate(datepicker_settings.dateFormat, me.selected_task.date, datepicker_settings);
                if (startdate > duedate) {
                    alert(rcmail.gettext('invalidstartduedates', 'tasklist'));
                    return false;
                }
            }

            $('input[name="tags[]"]', rcmail.gui_objects.edittagline).each(function(i,elem){
                if (elem.value)
                    me.selected_task.tags.push(elem.value);
            });

            // serialize alarm settings
            var alarm = $('#taskedit select.edit-alarm-type').val();
            if (alarm) {
                var val, offset = $('#taskedit select.edit-alarm-offset').val();
                if (offset == '@')
                    me.selected_task.alarms = '@' + date2unixtime(parse_datetime($('#taskedit input.edit-alarm-time').val(), $('#taskedit input.edit-alarm-date').val())) + ':' + alarm;
              else if ((val = parseInt($('#taskedit input.edit-alarm-value').val())) && !isNaN(val) && val >= 0)
                    me.selected_task.alarms = offset[0] + val + offset[1] + ':' + alarm;
            }

            // uploaded attachments list
            for (var i in rcmail.env.attachments) {
                if (i.match(/^rcmfile(.+)/))
                    me.selected_task.attachments.push(RegExp.$1);
            }

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

        if (action != 'new') {
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
          minHeight: 340,
          minWidth: 500,
          width: 580
        }).append(editform.show());  // adding form content AFTERWARDS massively speeds up opening on IE

        title.select();
    }


    /**
     * Open a task attachment either in a browser window for inline view or download it
     */
    function load_attachment(rec, att)
    {
        // can't open temp attachments
        if (!rec.id || rec.id < 0)
            return false;

        var qstring = '_id='+urlencode(att.id)+'&_t='+urlencode(rec.recurrence_id||rec.id)+'&_list='+urlencode(rec.list);

        // open attachment in frame if it's of a supported mimetype
        // similar as in app.js and calendar_ui.js
        if (att.id && att.mimetype && $.inArray(att.mimetype, settings.mimetypes)>=0) {
            rcmail.attachment_win = window.open(rcmail.env.comm_path+'&_action=get-attachment&'+qstring+'&_frame=1', 'rcubetaskattachment');
            if (rcmail.attachment_win) {
                window.setTimeout(function() { rcmail.attachment_win.focus(); }, 10);
                return;
            }
        }

        rcmail.goto_url('get-attachment', qstring+'&_download=1', false);
    };

    /**
     * Build task attachments list
     */
    function task_show_attachments(list, container, rec, edit)
    {
        var i, id, len, content, li, elem,
            ul = $('<ul>').addClass('attachmentslist');

        for (i=0, len=list.length; i<len; i++) {
            elem = list[i];
            li = $('<li>').addClass(elem.classname);

            if (edit) {
                rcmail.env.attachments[elem.id] = elem;
                // delete icon
                content = $('<a>')
                    .attr('href', '#delete')
                    .attr('title', rcmail.gettext('delete'))
                    .addClass('delete')
                    .click({ id:elem.id }, function(e) {
                        remove_attachment(this, e.data.id);
                        return false;
                    });

                if (!rcmail.env.deleteicon) {
                    content.html(rcmail.gettext('delete'));
                }
                else {
                    $('<img>').attr('src', rcmail.env.deleteicon).attr('alt', rcmail.gettext('delete')).appendTo(content);
                }

                li.append(content);
            }

            // name/link
            $('<a>')
                .attr('href', '#load')
                .addClass('file')
                .html(elem.name).click({ task:rec, att:elem }, function(e) {
                    load_attachment(e.data.task, e.data.att);
                    return false;
                }).appendTo(li);

            ul.append(li);
        }

        if (edit && rcmail.gui_objects.attachmentlist) {
            ul.id = rcmail.gui_objects.attachmentlist.id;
            rcmail.gui_objects.attachmentlist = ul.get(0);
        }

        container.empty().append(ul);
    };

    /**
     *
     */
    var remove_attachment = function(elem, id)
    {
        $(elem.parentNode).hide();
        me.selected_task.deleted_attachments.push(id);
        delete rcmail.env.attachments[id];
    };

    /**
     *
     */
    function add_childtask(id)
    {
        var rec = listdata[id];
        task_edit_dialog(null, 'new', { parent_id:id, list:rec.list });
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
     * Check if the given task matches the current filtermask and tag selection
     */
    function match_filter(rec)
    {
        var match = !filtermask || (filtermask & rec.mask) > 0;

        if (match && tagsfilter.length) {
            match = rec.tags && rec.tags.length;
            for (var i=0; match && i < tagsfilter.length; i++) {
                if (rec.tags.indexOf(tagsfilter[i]) < 0)
                    match = false;
            }
        }

        return match;
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
        var name = $('#taskedit-tasklistame').prop('disabled', !list.editable).val(list.editname || list.name),
            alarms = $('#taskedit-showalarms').prop('checked', list.showalarms).get(0),
            parent = $('#taskedit-parentfolder').val(list.parentfolder);

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
      .data('id', id);
    }


    /****  calendaring utility functions  *****/
    /*  TO BE MOVED TO libcalendaring plugin  */

    var gmt_offset = (new Date().getTimezoneOffset() / -60) - (rcmail.env.calendar_settings.timezone || 0) - (rcmail.env.calendar_settings.dst || 0);
    var client_timezone = new Date().getTimezoneOffset();

    /**
     * from time and date strings to a real date object
     */
    function parse_datetime(time, date)
    {
        // we use the utility function from datepicker to parse dates
        var date = date ? $.datepicker.parseDate(datepicker_settings.dateFormat, date, datepicker_settings) : new Date();

        var time_arr = time.replace(/\s*[ap][.m]*/i, '').replace(/0([0-9])/g, '$1').split(/[:.]/);
        if (!isNaN(time_arr[0])) {
            date.setHours(time_arr[0]);
        if (time.match(/p[.m]*/i) && date.getHours() < 12)
            date.setHours(parseInt(time_arr[0]) + 12);
        else if (time.match(/a[.m]*/i) && date.getHours() == 12)
            date.setHours(0);
      }
      if (!isNaN(time_arr[1]))
            date.setMinutes(time_arr[1]);

      return date;
    }

    /**
     * Format the given date object according to user's prefs
     */
    function format_datetime(date, mode)
    {
        var format =
             mode == 2 ?  rcmail.env.calendar_settings['time_format'] :
            (mode == 1 ? rcmail.env.calendar_settings['date_format'] :
             rcmail.env.calendar_settings['date_format'] + '  '+ rcmail.env.calendar_settings['time_format']);

        return $.fullCalendar.formatDate(date, format);
    }

    /**
     * convert the given Date object into a unix timestamp respecting browser's and user's timezone settings
     */
    function date2unixtime(date)
    {
        var dst_offset = (client_timezone - date.getTimezoneOffset()) * 60;  // adjust DST offset
        return Math.round(date.getTime()/1000 + gmt_offset * 3600 + dst_offset);
    }

    /**
     *
     */
    function fromunixtime(ts)
    {
        ts -= gmt_offset * 3600;
        var date = new Date(ts * 1000),
            dst_offset = (client_timezone - date.getTimezoneOffset()) * 60;
        if (dst_offset)  // adjust DST offset
            date.setTime((ts + 3600) * 1000);
        return date;
    }

    // init dialog by default
    init_taskedit();
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

// from http://james.padolsey.com/javascript/sorting-elements-with-jquery/
jQuery.fn.sortElements = (function(){
    var sort = [].sort;

    return function(comparator, getSortable) {
        getSortable = getSortable || function(){ return this };

        var last = null;
        return sort.call(this, comparator).each(function(i){
            // at this point the array is sorted, so we can just detach each one from wherever it is, and add it after the last
            var node = $(getSortable.call(this));
            var parent = node.parent();
            if (last) last.after(node);
            else      parent.prepend(node);
            last = node;
        });
    };
})();


/* tasklist plugin UI initialization */
var rctasks;
window.rcmail && rcmail.addEventListener('init', function(evt) {

  rctasks = new rcube_tasklist_ui(rcmail.env.tasklist_settings);

  // register button commands
  rcmail.register_command('newtask', function(){ rctasks.edit_task(null, 'new', {}); }, true);
  //rcmail.register_command('print', function(){ rctasks.print_list(); }, true);

  rcmail.register_command('list-create', function(){ rctasks.list_edit_dialog(null); }, true);
  rcmail.register_command('list-edit', function(){ rctasks.list_edit_dialog(rctasks.selected_list); }, false);
  rcmail.register_command('list-remove', function(){ rctasks.list_remove(rctasks.selected_list); }, false);

  rcmail.register_command('search', function(){ rctasks.quicksearch(); }, true);
  rcmail.register_command('reset-search', function(){ rctasks.reset_search(); }, true);

  rctasks.init();
});
