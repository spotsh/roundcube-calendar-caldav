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
    // extend base class
    rcube_libcalendaring.call(this, settings);

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
    var focusview;
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
    var task_draghelper;
    var tag_draghelper;
    var me = this;

    // general datepicker settings
    var datepicker_settings = {
      // translate from PHP format to datepicker format
      dateFormat: settings['date_format'].replace(/M/g, 'm').replace(/mmmmm/, 'MM').replace(/mmm/, 'M').replace(/dddd/, 'DD').replace(/ddd/, 'D').replace(/yy/g, 'y'),
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
    this.expand_collapse = expand_collapse;
    this.list_remove = list_remove;
    this.list_edit_dialog = list_edit_dialog;
    this.unlock_saving = unlock_saving;

    /* imports */
    var Q = this.quote_html;
    var text2html = this.text2html;
    var event_date_text = this.event_date_text;
    var parse_datetime = this.parse_datetime;
    var date2unixtime = this.date2unixtime;
    var fromunixtime = this.fromunixtime;
    var init_alarms_edit = this.init_alarms_edit;

    /**
     * initialize the tasks UI
     */
    function init()
    {
        // initialize task list selectors
        for (var id in me.tasklists) {
            if ((li = rcmail.get_folder_li(id, 'rcmlitasklist'))) {
                init_tasklist_li(li, id);
            }

            if (me.tasklists[id].editable && !me.selected_list) {
                me.selected_list = id;
                rcmail.enable_command('addtask', true);
                $(li).click();
            }
        }

        // register server callbacks
        rcmail.addEventListener('plugin.data_ready', data_ready);
        rcmail.addEventListener('plugin.update_task', update_taskitem);
        rcmail.addEventListener('plugin.refresh_tasks', function(p) { update_taskitem(p, true); });
        rcmail.addEventListener('plugin.update_counts', update_counts);
        rcmail.addEventListener('plugin.insert_tasklist', insert_list);
        rcmail.addEventListener('plugin.update_tasklist', update_list);
        rcmail.addEventListener('plugin.destroy_tasklist', destroy_list);
        rcmail.addEventListener('plugin.reload_data', function(){ list_tasks(null); });
        rcmail.addEventListener('plugin.unlock_saving', unlock_saving);
        rcmail.addEventListener('requestrefresh', before_refresh);

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
            var tasktext = this.elements.text.value,
                rec = { id:-(++idcount), title:tasktext, readonly:true, mask:0, complete:0 };

            if (tasktext && tasktext.length) {
                save_task({ tempid:rec.id, raw:tasktext, list:me.selected_list }, 'new');
                render_task(rec);

                $('#listmessagebox').hide();
            }

            // clear form
            this.reset();
            return false;
        }).find('input[type=text]').placeholder(rcmail.gettext('createnewtask','tasklist'));

        // click-handler on tags list
        $(rcmail.gui_objects.tagslist).click(function(e){
            if (e.target.nodeName != 'LI')
                return false;

            var item = $(e.target),
                tag = item.data('value');

            // reset selection on regular clicks
            var index = $.inArray(tag, tagsfilter);
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

            // clear text selection in IE after shift+click
            if (shift && document.selection)
              document.selection.empty();

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
            var className = e.target.className;

            if (item.hasClass('childtoggle')) {
                item = item.parent().find('.taskhead');
                className = 'childtoggle';
            }
            else if (!item.hasClass('taskhead'))
                item = item.closest('div.taskhead');

            // ignore
            if (!item.length)
                return false;

            var id = item.data('id'),
                li = item.parent(),
                rec = listdata[id];
            
            switch (className) {
                case 'childtoggle':
                    rec.collapsed = !rec.collapsed;
                    li.children('.childtasks:first').toggle();
                    $(e.target).toggleClass('collapsed').html(rec.collapsed ? '&#9654;' : '&#9660;');
                    rcmail.http_post('tasks/task', { action:'collapse', t:{ id:rec.id, list:rec.list }, collapsed:rec.collapsed?1:0 });
                    if (e.shiftKey)  // expand/collapse all childs
                        li.children('.childtasks:first .childtoggle.'+(rec.collapsed?'expanded':'collapsed')).click();
                    break;

                case 'complete':
                    if (rcmail.busy)
                        return false;

                    rec.complete = e.target.checked ? 1 : 0;
                    li.toggleClass('complete');
                    save_task(rec, 'edit');
                    return true;
                
                case 'flagged':
                    if (rcmail.busy)
                        return false;

                    rec.flagged = rec.flagged ? 0 : 1;
                    li.toggleClass('flagged');
                    save_task(rec, 'edit');
                    break;
                
                case 'date':
                    if (rcmail.busy)
                        return false;

                    var link = $(e.target).html(''),
                        input = $('<input type="text" size="10" />').appendTo(link).val(rec.date || '')

                    input.datepicker($.extend({
                        onClose: function(dateText, inst) {
                            if (dateText != (rec.date || '')) {
                                rec.date = dateText;
                                save_task(rec, 'edit');
                            }
                            input.datepicker('destroy').remove();
                            link.html(dateText || rcmail.gettext('nodate','tasklist'));
                        }
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

                case 'extlink':
                    return true;

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

            if (!rcmail.busy && item.length && (id = item.data('id')) && (rec = listdata[id])) {
                var list = rec.list && me.tasklists[rec.list] ? me.tasklists[rec.list] : {};
                if (rec.readonly || !list.editable)
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
                        .unbind('click')
                        .bind('click', function(e){
                            $(input).datepicker('setDate', null).datepicker('hide');
                        });
                }, 1);
            }
        }, datepicker_settings);
    }

    /**
     * initialize task edit form elements
     */
    function init_taskedit()
    {
        $('#taskedit').tabs();

        var completeness_slider_change = function(e, ui){
          var v = completeness_slider.slider('value');
          if (v >= 98) v = 100;
          if (v <= 2)  v = 0;
          $('#taskedit-completeness').val(v);
        };
        completeness_slider = $('#taskedit-completeness-slider').slider({
            range: 'min',
            animate: 'fast',
            slide: completeness_slider_change,
            change: completeness_slider_change
        });
        $('#taskedit-completeness').change(function(e){
            completeness_slider.slider('value', parseInt(this.value))
        });

        // register events on alarm fields
        init_alarms_edit('#taskedit');

        $('#taskedit-date, #taskedit-startdate').datepicker(datepicker_settings);

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
            data_ready({ data:[], lists:'', filter:basefilter, search:search_query });
        else
            render_tasklist();

        $('#taskselector li.selected').removeClass('selected');
        $('#taskselector li.'+selector).addClass('selected');
    }

    /**
     * Remove all tasks of the given list from the UI
     */
    function remove_tasks(list_id)
    {
        // remove all tasks of the given list from index
        var newindex = $.grep(listindex, function(id, i){
            return listdata[id] && listdata[id].list != list_id;
        });

        listindex = newindex;
        render_tasklist();

        // avoid reloading
        me.tasklists[list_id].active = false;
        loadstate.lists = active_lists();
    }

    /**
     * Modify query parameters for refresh requests
     */
    function before_refresh(query)
    {
        query.filter = filtermask == FILTER_MASK_COMPLETE ? FILTER_MASK_COMPLETE : FILTER_MASK_ALL;
        query.lists = active_lists().join(',');
        if (search_query)
            query.q = search_query;

        return query;
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

        for (var id, i=0; i < response.data.length; i++) {
            id = response.data[i].id;
            listindex.push(id);
            listdata[id] = response.data[i];
            listdata[id].children = [];
            // register a forward-pointer to child tasks
            if (listdata[id].parent_id && listdata[listdata[id].parent_id])
                listdata[listdata[id].parent_id].children.push(id);
        }

        append_tags(response.tags || []);
        render_tasklist();

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
            cache = {},
            activetags = {},
            msgbox = $('#listmessagebox').hide(),
            list = $(rcmail.gui_objects.resultlist).html('');

        for (var i=0; i < listindex.length; i++) {
            id = listindex[i];
            rec = listdata[id];
            if (match_filter(rec, cache)) {
                render_task(rec);
                count++;

                // keep a list of tags from all visible tasks
                for (var t, j=0; rec.tags && j < rec.tags.length; j++) {
                    t = rec.tags[j];
                    if (typeof activetags[t] == 'undefined')
                        activetags[t] = 0;
                    activetags[t]++;
                }
            }
        }

        fix_tree_toggles();
        update_tagcloud(activetags);

        if (!count)
            msgbox.html(rcmail.gettext('notasksfound','tasklist')).show();
    }

    /**
     * Show/hide child toggle buttons on all visible task items
     */
    function fix_tree_toggles()
    {
        $('.taskitem', rcmail.gui_objects.resultlist).each(function(i,elem){
            var li = $(elem),
                rec = listdata[li.attr('rel')],
                childs = $('.childtasks li', li);

            $('.childtoggle', li)[(childs.length ? 'show' : 'hide')]();
        })
    }

    /**
     * Expand/collapse all task items with childs
     */
    function expand_collapse(expand)
    {
        var collapsed = !expand;

        $('.taskitem .childtasks')[(collapsed ? 'hide' : 'show')]();
        $('.taskitem .childtoggle')
            .removeClass(collapsed ? 'expanded' : 'collapsed')
            .addClass(collapsed ? 'collapsed' : 'expanded')
            .html(collapsed ? '&#9654;' : '&#9660;');

        // store new toggle collapse states
        var ids = [];
        for (var id in listdata) {
            if (listdata[id].children && listdata[id].children.length)
                ids.push(id);
        }
        if (ids.length) {
            rcmail.http_post('tasks/task', { action:'collapse', t:{ id:ids.join(',') }, collapsed:collapsed?1:0 });
        }
    }

    /**
     *
     */
    function append_tags(taglist)
    {
        // find new tags
        var newtags = [];
        for (var i=0; i < taglist.length; i++) {
            if ($.inArray(taglist[i], tags) < 0)
                newtags.push(taglist[i]);
        }
        tags = tags.concat(newtags);

        // append new tags to tag cloud
        $.each(newtags, function(i, tag){
            $('<li>').attr('rel', tag).data('value', tag)
                .html(Q(tag) + '<span class="count"></span>')
                .appendTo(rcmail.gui_objects.tagslist)
                .draggable({
                    addClasses: false,
                    revert: 'invalid',
                    revertDuration: 300,
                    helper: tag_draggable_helper,
                    start: tag_draggable_start,
                    appendTo: 'body',
                    cursor: 'pointer'
                });
            });

        // re-sort tags list
        $(rcmail.gui_objects.tagslist).children('li').sortElements(function(a,b){
            return $.text([a]).toLowerCase() > $.text([b]).toLowerCase() ? 1 : -1;
        });
    }

    /**
     * Display the given counts to each tag and set those inactive which don't
     * have any matching tasks in the current view.
     */
    function update_tagcloud(counts)
    {
        // compute counts first by iterating over all visible task items
        if (typeof counts == 'undefined') {
            counts = {};
            $('li.taskitem', rcmail.gui_objects.resultlist).each(function(i,li){
                var t, id = $(li).attr('rel'),
                    rec = listdata[id];
                for (var j=0; rec && rec.tags && j < rec.tags.length; j++) {
                    t = rec.tags[j];
                    if (typeof counts[t] == 'undefined')
                        counts[t] = 0;
                    counts[t]++;
                }
            });
        }

        $(rcmail.gui_objects.tagslist).children('li').each(function(i,li){
            var elem = $(li), tag = elem.attr('rel'),
                count = counts[tag] || 0;

            elem.children('.count').html(count+'');
            if (count == 0) elem.addClass('inactive');
            else            elem.removeClass('inactive');
        });
    }

    /*  Helper functions for drag & drop functionality of tags  */
    
    function tag_draggable_helper()
    {
        if (!tag_draghelper)
            tag_draghelper = $('<div class="tag-draghelper"></div>');
        else
            tag_draghelper.html('');

        $(this).clone().addClass('tag').appendTo(tag_draghelper);
        return tag_draghelper;
    }

    function tag_draggable_start(event, ui)
    {
        $('.taskhead').droppable({
            hoverClass: 'droptarget',
            accept: tag_droppable_accept,
            drop: tag_draggable_dropped,
            addClasses: false
        });
    }

    function tag_droppable_accept(draggable)
    {
        if (rcmail.busy)
            return false;

        var tag = draggable.data('value'),
            drop_id = $(this).data('id'),
            drop_rec = listdata[drop_id];

        // target already has this tag assigned
        if (!drop_rec || (drop_rec.tags && $.inArray(tag, drop_rec.tags) >= 0)) {
            return false;
        }

        return true;
    }

    function tag_draggable_dropped(event, ui)
    {
        var drop_id = $(this).data('id'),
            tag = ui.draggable.data('value'),
            rec = listdata[drop_id];

        if (rec && rec.id) {
            if (!rec.tags) rec.tags = [];
            rec.tags.push(tag);
            save_task(rec, 'edit');
        }
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
            if (f != 'all')
                link.children('span').html(taskcounts[f] || '')[(taskcounts[f] ? 'show' : 'hide')]();
        });

        // spacial case: overdue
        $('#taskselector li.overdue')[(taskcounts.overdue ? 'removeClass' : 'addClass')]('inactive');
    }

    /**
     * Callback from server to update a single task item
     */
    function update_taskitem(rec, filter)
    {
        // handle a list of task records
        if ($.isArray(rec)) {
            $.each(rec, function(i,r){ update_taskitem(r, filter); });
            return;
        }

        var id = rec.id,
            oldid = rec.tempid || id,
            oldrec = listdata[oldid],
            oldindex = $.inArray(oldid, listindex),
            oldparent = oldrec ? (oldrec._old_parent_id || oldrec.parent_id) : null,
            list = me.tasklists[rec.list];

        if (oldindex >= 0)
            listindex[oldindex] = id;
        else
            listindex.push(id);

        listdata[id] = rec;

        // remove child-pointer from old parent
        if (oldparent && listdata[oldparent] && oldparent != rec.parent_id) {
            var oldchilds = listdata[oldparent].children,
                i = $.inArray(oldid, oldchilds);
            if (i >= 0) {
                listdata[oldparent].children = oldchilds.slice(0,i).concat(oldchilds.slice(i+1));
            }
        }

        // register a forward-pointer to child tasks
        if (rec.parent_id && listdata[rec.parent_id] && listdata[rec.parent_id].children && $.inArray(id, listdata[rec.parent_id].children) < 0)
            listdata[rec.parent_id].children.push(id);

        // restore pointers to my children
        if (!listdata[id].children) {
            listdata[id].children = [];
            for (var pid in listdata) {
                if (listdata[pid].parent_id == id)
                    listdata[id].children.push(pid);
            }
        }

        if (list.active) {
            if (!filter || match_filter(rec, {}))
                render_task(rec, oldid);
        }
        else {
            $('li[rel="'+id+'"]', rcmail.gui_objects.resultlist).remove();
        }

        append_tags(rec.tags || []);
        update_tagcloud();
        fix_tree_toggles();
    }

    /**
     * Submit the given (changed) task record to the server
     */
    function save_task(rec, action)
    {
        if (!rcmail.busy) {
            saving_lock = rcmail.set_busy(true, 'tasklist.savingdata');
            rcmail.http_post('tasks/task', { action:action, t:rec, filter:filtermask });
            $('button.ui-button:ui-button').button('option', 'disabled', rcmail.busy);
            return true;
        }
        
        return false;
    }

    /**
     * Remove saving lock and free the UI for new input
     */
    function unlock_saving()
    {
        if (saving_lock) {
            rcmail.set_busy(false, null, saving_lock);
            $('button.ui-button:ui-button').button('option', 'disabled', false);
        }
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
            '<span class="title">' + text2html(Q(rec.title)) + '</span>' +
            '<span class="tags">' + tags_html + '</span>' +
            '<span class="date">' + Q(rec.date || rcmail.gettext('nodate','tasklist')) + '</span>' +
            '<a href="#" class="actions">V</a>'
            )
            .data('id', rec.id)
            .draggable({
                revert: 'invalid',
                addClasses: false,
                cursorAt: { left:-10, top:12 },
                helper: task_draggable_helper,
                appendTo: 'body',
                start: task_draggable_start,
                stop: task_draggable_stop,
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

        var li, inplace = false, parent = rec.parent_id ? $('li[rel="'+rec.parent_id+'"] > ul.childtasks', rcmail.gui_objects.resultlist) : null;
        if (replace && (li = $('li[rel="'+replace+'"]', rcmail.gui_objects.resultlist)) && li.length) {
            li.children('div.taskhead').first().replaceWith(div);
            li.attr('rel', rec.id);
            inplace = true;
        }
        else {
            li = $('<li>')
                .attr('rel', rec.id)
                .addClass('taskitem')
                .append((rec.collapsed ? '<span class="childtoggle collapsed">&#9654;' : '<span class="childtoggle expanded">&#9660;') + '</span>')
                .append(div)
                .append('<ul class="childtasks" style="' + (rec.collapsed ? 'display:none' : '') + '"></ul>');

            if (!parent || !parent.length)
                li.appendTo(rcmail.gui_objects.resultlist);
        }

        if (!inplace && parent && parent.length)
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
        var dir = 0, index, slice, cmp, next_li, next_id, next_rec, insert_after, past_myself;

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
        var oldindex = $.inArray(rec.id, listindex);
        if (oldindex >= 0) {
            slice = listindex.slice(0,oldindex);
            listindex = slice.concat(listindex.slice(oldindex+1));
        }

        // find the right place to insert the task item
        li.parent().children('.taskitem').each(function(i, elem){
            next_li = $(elem);
            next_id = next_li.attr('rel');
            next_rec = listdata[next_id];

            if (next_id == rec.id) {
                past_myself = true;
                return 1; // continue
            }

            cmp = next_rec ? task_cmp(rec, next_rec) : 0;

            if (cmp > 0 || (cmp == 0 && !past_myself)) {
                insert_after = next_li;
                return 1; // continue;
            }
            else if (next_li && cmp < 0) {
                if (animated) insert_animated(li, next_li);
                else          li.insertBefore(next_li);
                index = $.inArray(next_id, listindex);
                return false; // break
            }
        });

        if (insert_after) {
            if (animated) insert_animated(li, null, insert_after);
            else          li.insertAfter(insert_after);

            next_id = insert_after.attr('rel');
            index = $.inArray(next_id, listindex);
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

    /**
     *
     */
    function get_all_childs(id)
    {
        var cid, childs = [];
        for (var i=0; listdata[id].children && i < listdata[id].children.length; i++) {
            cid = listdata[id].children[i];
            childs.push(cid);
            childs = childs.concat(get_all_childs(cid));
        }

        return childs;
    }


    /*  Helper functions for drag & drop functionality  */
    
    function task_draggable_helper()
    {
        if (!task_draghelper)
            task_draghelper = $('<div class="taskitem-draghelper">&#x2714;</div>');

        return task_draghelper;
    }

    function task_draggable_start(event, ui)
    {
        $('.taskhead, #rootdroppable, #'+rcmail.gui_objects.folderlist.id+' li').droppable({
            hoverClass: 'droptarget',
            accept: task_droppable_accept,
            drop: task_draggable_dropped,
            addClasses: false
        });

        $(this).parent().addClass('dragging');
        $('#rootdroppable').show();
    }

    function task_draggable_stop(event, ui)
    {
        $(this).parent().removeClass('dragging');
        $('#rootdroppable').hide();
    }

    function task_droppable_accept(draggable)
    {
        if (rcmail.busy)
            return false;

        var drag_id = draggable.data('id'),
            drop_id = $(this).data('id'),
            drag_rec = listdata[drag_id] || {},
            drop_rec = listdata[drop_id];

        // drop target is another list
        if (drag_rec && $(this).data('type') == 'tasklist') {
            var  drop_list = me.tasklists[drop_id],
               from_list = me.tasklists[drag_rec.list];
            return !drag_rec.parent_id && drop_id != drag_rec.list && drop_list && drop_list.editable && from_list && from_list.editable;
        }

        if (drop_rec && drop_rec.list != drag_rec.list)
            return false;

        if (drop_id == drag_rec.parent_id)
            return false;

        while (drop_rec && drop_rec.parent_id) {
            if (drop_rec.parent_id == drag_id)
                return false;
            drop_rec = listdata[drop_rec.parent_id];
        }

        return true;
    }

    function task_draggable_dropped(event, ui)
    {
        var drop_id = $(this).data('id'),
            task_id = ui.draggable.data('id'),
            rec = listdata[task_id],
            parent, li;

        // dropped on another list -> move
        if ($(this).data('type') == 'tasklist') {
            if (rec) {
                save_task({ id:rec.id, list:drop_id, _fromlist:rec.list }, 'move');
                rec.list = drop_id;
            }
        }
        // dropped on a new parent task or root
        else {
            parent = drop_id ? $('li[rel="'+drop_id+'"] > ul.childtasks', rcmail.gui_objects.resultlist) : $(rcmail.gui_objects.resultlist)

            if (rec && parent.length) {
                // submit changes to server
                rec._old_parent_id = rec.parent_id;
                rec.parent_id = drop_id || 0;
                save_task(rec, 'edit');

                li = ui.draggable.parent();
                li.slideUp(300, function(){
                    li.appendTo(parent);
                    resort_task(rec, li);
                    li.slideDown(300);
                    fix_tree_toggles();
                });
            }
        }
    }


    /**
     * Show task details in a dialog
     */
    function task_show_dialog(id)
    {
        var $dialog = $('#taskshow'), rec;

        if ($dialog.is(':ui-dialog'))
          $dialog.dialog('close');

        if (!(rec = listdata[id]) || clear_popups({}))
            return;

        me.selected_task = rec;

        // fill dialog data
        $('#task-parent-title').html(Q(rec.parent_title || '')+' &raquo;').css('display', rec.parent_title ? 'block' : 'none');
        $('#task-title').html(text2html(Q(rec.title || '')));
        $('#task-description').html(text2html(rec.description || '', 300, 6))[(rec.description ? 'show' : 'hide')]();
        $('#task-date')[(rec.date ? 'show' : 'hide')]().children('.task-text').html(Q(rec.date || rcmail.gettext('nodate','tasklist')));
        $('#task-time').html(Q(rec.time || ''));
        $('#task-start')[(rec.startdate ? 'show' : 'hide')]().children('.task-text').html(Q(rec.startdate || ''));
        $('#task-starttime').html(Q(rec.starttime || ''));
        $('#task-alarm')[(rec.alarms_text ? 'show' : 'hide')]().children('.task-text').html(Q(rec.alarms_text));
        $('#task-completeness .task-text').html(((rec.complete || 0) * 100) + '%');
        $('#task-list .task-text').html(Q(me.tasklists[rec.list] ? me.tasklists[rec.list].name : ''));

        var itags = get_inherited_tags(rec);
        var taglist = $('#task-tags')[(rec.tags && rec.tags.length || itags.length ? 'show' : 'hide')]().children('.task-text').empty();
        if (rec.tags && rec.tags.length) {
            $.each(rec.tags, function(i,val){
                $('<span>').addClass('tag-element').html(Q(val)).appendTo(taglist);
            });
        }

        // append inherited tags
        if (itags.length) {
            $.each(itags, function(i,val){
                if (!rec.tags || $.inArray(val, rec.tags) < 0)
                    $('<span>').addClass('tag-element inherit').html(Q(val)).appendTo(taglist);
            });
            // re-sort tags list
            $(taglist).children().sortElements(function(a,b){
                return $.text([a]).toLowerCase() > $.text([b]).toLowerCase() ? 1 : -1;
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
        var buttons = [];
        buttons.push({
            text: rcmail.gettext('edit','tasklist'),
            click: function() {
                task_edit_dialog(me.selected_task.id, 'edit');
            },
            disabled: rcmail.busy
        });

        buttons.push({
            text: rcmail.gettext('delete','tasklist'),
            click: function() {
                if (delete_task(me.selected_task.id))
                    $dialog.dialog('close');
            },
            disabled: rcmail.busy
        });

        // open jquery UI dialog
        $dialog.dialog({
          modal: false,
          resizable: true,
          closeOnEscape: true,
          title: rcmail.gettext('taskdetails', 'tasklist'),
          open: function() {
            $dialog.parent().find('.ui-button').first().focus();
          },
          close: function() {
              $dialog.dialog('destroy').appendTo(document.body);
          },
          buttons: buttons,
          minWidth: 500,
          width: 580
        }).show();

        // set dialog size according to content
        me.dialog_resize($dialog.get(0), $dialog.height(), 580);
    }

    /**
     * Opens the dialog to edit a task
     */
    function task_edit_dialog(id, action, presets)
    {
        $('#taskshow:ui-dialog').dialog('close');

        var rec = listdata[id] || presets,
            $dialog = $('<div>'),
            editform = $('#taskedit'),
            list = rec.list && me.tasklists[rec.list] ? me.tasklists[rec.list] :
                (me.selected_list ? me.tasklists[me.selected_list] : { editable: action=='new' });

        if (rcmail.busy || !list.editable || (action == 'edit' && (!rec || rec.readonly)))
            return false;

        me.selected_task = $.extend({ alarms:'' }, rec);  // clone task object
        rec =  me.selected_task;

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
        if (rec.alarms || action != 'new') {
          var valarms = (typeof rec.alarms == 'string' ? rec.alarms.split(';') : rec.alarms) || [''];
          for (var alarm, i=0; i < valarms.length; i++) {
              alarm = String(valarms[i]).split(':');
              if (!alarm[1] && alarm[0]) alarm[1] = 'DISPLAY';
              $('#taskedit select.edit-alarm-type').val(alarm[1]);

              if (alarm[0].match(/@(\d+)/)) {
                  var ondate = fromunixtime(parseInt(RegExp.$1));
                  $('#taskedit select.edit-alarm-offset').val('@');
                  $('#taskedit input.edit-alarm-date').val(me.format_datetime(ondate, 1));
                  $('#taskedit input.edit-alarm-time').val(me.format_datetime(ondate, 2));
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
        rcmail.enable_command('remove-attachment', list.editable);
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
            if (!me.selected_task.title || !me.selected_task.title.length) {
                title.focus();
                return false;
            }
            else if (me.selected_task.startdate && me.selected_task.date) {
                var startdate = $.datepicker.parseDate(datepicker_settings.dateFormat, me.selected_task.startdate, datepicker_settings);
                var duedate = $.datepicker.parseDate(datepicker_settings.dateFormat, me.selected_task.date, datepicker_settings);
                if (startdate > duedate) {
                    alert(rcmail.gettext('invalidstartduedates', 'tasklist'));
                    return false;
                }
            }

            // collect tags
            $('input[type="hidden"]', rcmail.gui_objects.edittagline).each(function(i,elem){
                if (elem.value)
                    me.selected_task.tags.push(elem.value);
            });
            // including the "pending" one in the text box
            var newtag = $('#tagedit-input').val();
            if (newtag != '') {
                me.selected_task.tags.push(newtag);
            }

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

            // task assigned to a new list
            if (me.selected_task.list && listdata[id] && me.selected_task.list != listdata[id].list) {
                me.selected_task._fromlist = rec.list;
            }

            me.selected_task.complete = complete.val() / 100;
            if (isNaN(me.selected_task.complete))
                me.selected_task.complete = null;

            if (!me.selected_task.list && list.id)
                me.selected_task.list = list.id;

            if (!me.selected_task.tags.length)
                me.selected_task.tags = '';

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
          minHeight: 460,
          minWidth: 500,
          width: 580
        }).append(editform.show());  // adding form content AFTERWARDS massively speeds up opening on IE

        title.select();

        // set dialog size according to content
        me.dialog_resize($dialog.get(0), $dialog.height(), 580);
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
            if (rcmail.open_window(rcmail.env.comm_path+'&_action=get-attachment&'+qstring+'&_frame=1', true, true)) {
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
        if (rcmail.busy)
            return false;

        var rec = listdata[id];
        task_edit_dialog(null, 'new', { parent_id:id, list:rec.list });
    }

    /**
     * Delete the given task
     */
    function delete_task(id)
    {
        var rec = listdata[id];
        if (!rec || rec.readonly || rcmail.busy)
            return false;

        var html, buttons = [{
            text: rcmail.gettext('cancel', 'tasklist'),
            click: function() {
                $(this).dialog('close');
            }
        }];

        if (rec.children && rec.children.length) {
            html = rcmail.gettext('deleteparenttasktconfirm','tasklist');
            buttons.push({
                text: rcmail.gettext('deletethisonly','tasklist'),
                click: function() {
                    _delete_task(id, 0);
                    $(this).dialog('close');
                }
            });
            buttons.push({
                text: rcmail.gettext('deletewithchilds','tasklist'),
                click: function() {
                    _delete_task(id, 1);
                    $(this).dialog('close');
                }
            });
        }
        else {
            html = rcmail.gettext('deletetasktconfirm','tasklist');
            buttons.push({
                text: rcmail.gettext('delete','tasklist'),
                click: function() {
                    _delete_task(id, 0);
                    $(this).dialog('close');
                }
            });
        }

        var $dialog = $('<div>').html(html);
        $dialog.dialog({
          modal: true,
          width: 520,
          dialogClass: 'warning',
          title: rcmail.gettext('deletetask', 'tasklist'),
          buttons: buttons,
          close: function(){
              $dialog.dialog('destroy').hide();
          }
        }).addClass('tasklist-confirm').show();

        return true;
    }

    /**
     * Subfunction to submit the delete command after confirm
     */
    function _delete_task(id, mode)
    {
        var rec = listdata[id],
            li = $('li[rel="'+id+'"]', rcmail.gui_objects.resultlist).hide();

        saving_lock = rcmail.set_busy(true, 'tasklist.savingdata');
        rcmail.http_post('task', { action:'delete', t:{ id:rec.id, list:rec.list }, mode:mode, filter:filtermask });

        // move childs to parent/root
        if (mode != 1 && rec.children !== undefined) {
            var parent_node = rec.parent_id ? $('li[rel="'+rec.parent_id+'"] > .childtasks', rcmail.gui_objects.resultlist) : null;
            if (!parent_node || !parent_node.length)
                parent_node = rcmail.gui_objects.resultlist;

            $.each(rec.children, function(i,cid) {
                var child = listdata[cid];
                child.parent_id = rec.parent_id;
                resort_task(child, $('li[rel="'+cid+'"]').appendTo(parent_node), true);
            });
        }

        li.remove();
    }

    /**
     * Check if the given task matches the current filtermask and tag selection
     */
    function match_filter(rec, cache, recursive)
    {
        // return cached result
        if (typeof cache[rec.id] != 'undefined' && recursive != 2) {
            return cache[rec.id];
        }

        var match = !filtermask || (filtermask & rec.mask) > 0;

        // in focusview mode, only tasks from the selected list are allowed
        if (focusview && rec.list != focusview)
            match = false;

        if (match && tagsfilter.length) {
            match = rec.tags && rec.tags.length;
            var alltags = get_inherited_tags(rec).concat(rec.tags || []);
            for (var i=0; match && i < tagsfilter.length; i++) {
                if ($.inArray(tagsfilter[i], alltags) < 0)
                    match = false;
            }
        }

        // check if a child task matches the tags
        if (!match && (recursive||0) < 2 && rec.children && rec.children.length) {
            for (var j=0; !match && j < rec.children.length; j++) {
                match = match_filter(listdata[rec.children[j]], cache, 1);
            }
        }

        // walk up the task tree and check if a parent task matches
        var parent_id;
        if (!match && !recursive && (parent_id = rec.parent_id)) {
            while (!match && parent_id && listdata[parent_id]) {
                match = match_filter(listdata[parent_id], cache, 2);
                parent_id = listdata[parent_id].parent_id;
            }
        }

        if (recursive != 1) {
            cache[rec.id] = match;
        }
        return match;
    }

    /**
     *
     */
    function get_inherited_tags(rec)
    {
        var parent_id, itags = [];

        if ((parent_id = rec.parent_id)) {
            while (parent_id && listdata[parent_id]) {
                itags = itags.concat(listdata[parent_id].tags || []);
                parent_id = listdata[parent_id].parent_id;
            }
        }

        return $.unqiqueStrings(itags);
    }

    /**
     *
     */
    function list_edit_dialog(id)
    {
        var list = me.tasklists[id],
            $dialog = $('#tasklistform');
            editform = $('#tasklisteditform');

        if ($dialog.is(':ui-dialog'))
            $dialog.dialog('close');

        if (!list)
            list = { name:'', editable:true, showalarms:true };

        // fill edit form
        var name = $('#taskedit-tasklistame').prop('disabled', list.norename||false).val(list.editname || list.name),
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
        if (list && !list.norename && confirm(rcmail.gettext(list.children ? 'deletelistconfirmrecursive' : 'deletelistconfirm', 'tasklist'))) {
            saving_lock = rcmail.set_busy(true, 'tasklist.savingdata');
            rcmail.http_post('tasklist', { action:'remove', l:{ id:list.id } });
            return true;
        }
        return false;
    }

    /**
     * Callback from server to finally remove the given list
     */
    function destroy_list(prop)
    {
        var li, delete_ids = [],
            list = me.tasklists[prop.id];

            // find sub-lists
        if (list && list.children) {
            for (var child_id in me.tasklists) {
                if (String(child_id).indexOf(prop.id) == 0)
                    delete_ids.push(child_id);
            }
        }
        else {
            delete_ids.push(prop.id);
        }

        // delete all calendars in the list
        for (var i=0; i < delete_ids.length; i++) {
            id = delete_ids[i];
            list = me.tasklists[id];
            li = rcmail.get_folder_li(id, 'rcmlitasklist');

            if (li) {
                $(li).remove();
            }
            if (list) {
                list.active = false;
                // delete me.tasklists[prop.id];
                unlock_saving();
                remove_tasks(list.id);
            }
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
        me.tasklists[prop.id] = prop;
        init_tasklist_li(li.get(0), prop.id);
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

            list_tasks();
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

    // resize and reposition (center) the dialog window
    this.dialog_resize = function(id, height, width)
    {
        var win = $(window), w = win.width(), h = win.height();
            $(id).dialog('option', { height: Math.min(h-20, height+130), width: Math.min(w-20, width+50) })
                .dialog('option', 'position', ['center', 'center']);  // only works in a separate call (!?)
    };

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
                if (!this.checked) remove_tasks(id);
                else               list_tasks(null);
                rcmail.http_post('tasklist', { action:'subscribe', l:{ id:id, active:me.tasklists[id].active?1:0 } });

                // disable focusview
                if (!this.checked && focusview == id) {
                    set_focusview(null);
                }
            }
        }).data('id', id).get(0).checked = me.tasklists[id].active || false;

        $(li).click(function(e){
            var id = $(this).data('id');
            rcmail.select_folder(id, 'rcmlitasklist');
            rcmail.enable_command('list-edit', 'list-remove', 'list-import', me.tasklists[id].editable);
            me.selected_list = id;

            // click on handle icon toggles focusview
            if (e.target.className == 'handle') {
                set_focusview(focusview == id ? null : id)
            }
            // disable focusview when selecting another list
            else if (focusview && id != focusview) {
                set_focusview(null);
            }
        })
        .dblclick(function(e){
            list_edit_dialog($(this).data('id'));
        })
        .data('id', id)
        .data('type', 'tasklist')
        .addClass(me.tasklists[id].editable ? null : 'readonly');
    }

    /**
     * Enable/disable focusview mode for the given list
     */
    function set_focusview(id)
    {
        if (focusview && focusview != id)
            $(rcmail.get_folder_li(focusview, 'rcmlitasklist')).removeClass('focusview');

        focusview = id;

        // activate list if necessary
        if (focusview && !me.tasklists[id].active) {
            $('input', rcmail.get_folder_li(id, 'rcmlitasklist')).get(0).checked = true;
            me.tasklists[id].active = true;
            fetch_counts();
        }

        // update list
        list_tasks(null);

        if (focusview) {
            $(rcmail.get_folder_li(focusview, 'rcmlitasklist')).addClass('focusview');
        }
    }


    // init dialog by default
    init_taskedit();
}


// extend jQuery
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

// equivalent to $.unique() but working on arrays of strings
jQuery.unqiqueStrings = (function() {
    return function(arr) {
        var hash = {}, out = [];

        for (var i = 0; i < arr.length; i++) {
            hash[arr[i]] = 0;
        }
        for (var val in hash) {
            out.push(val);
        }

        return out;
    };
})();


/* tasklist plugin UI initialization */
var rctasks;
window.rcmail && rcmail.addEventListener('init', function(evt) {

  rctasks = new rcube_tasklist_ui(rcmail.env.libcal_settings);

  // register button commands
  rcmail.register_command('newtask', function(){ rctasks.edit_task(null, 'new', {}); }, true);
  //rcmail.register_command('print', function(){ rctasks.print_list(); }, true);

  rcmail.register_command('list-create', function(){ rctasks.list_edit_dialog(null); }, true);
  rcmail.register_command('list-edit', function(){ rctasks.list_edit_dialog(rctasks.selected_list); }, false);
  rcmail.register_command('list-remove', function(){ rctasks.list_remove(rctasks.selected_list); }, false);

  rcmail.register_command('search', function(){ rctasks.quicksearch(); }, true);
  rcmail.register_command('reset-search', function(){ rctasks.reset_search(); }, true);
  rcmail.register_command('expand-all', function(){ rctasks.expand_collapse(true); }, true);
  rcmail.register_command('collapse-all', function(){ rctasks.expand_collapse(false); }, true);

  rctasks.init();
});
