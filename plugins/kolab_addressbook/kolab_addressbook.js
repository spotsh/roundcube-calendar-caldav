if (window.rcmail) {
    rcmail.addEventListener('init', function() {
        rcmail.set_book_actions();
        if (rcmail.gui_objects.editform && rcmail.env.action.match(/^plugin\.book/)) {
            rcmail.enable_command('book-save', true);
        }
    });
    rcmail.addEventListener('listupdate', function() {
        rcmail.set_book_actions();
    });
}

// (De-)activates address book management commands
rcube_webmail.prototype.set_book_actions = function()
{
    var source = this.env.source,
        sources = this.env.address_sources;

    this.enable_command('book-create', true);
    this.enable_command('book-edit', 'book-delete', source && sources[source] && sources[source].kolab && sources[source].editable);
};

rcube_webmail.prototype.book_create = function()
{
    this.book_show_contentframe('create');
};

rcube_webmail.prototype.book_edit = function()
{
    this.book_show_contentframe('edit');
};

rcube_webmail.prototype.book_delete = function()
{
    if (this.env.source != '' && confirm(this.get_label('kolab_addressbook.bookdeleteconfirm'))) {
        var lock = this.set_busy(true, 'kolab_addressbook.bookdeleting');
        this.http_request('plugin.book', '_act=delete&_source='+urlencode(this.book_realname()), lock);
    }
};

// displays page with book edit/create form
rcube_webmail.prototype.book_show_contentframe = function(action, framed)
{
    var add_url = '', target = window;

    // unselect contact
    this.contact_list.clear_selection();
    this.enable_command('edit', 'delete', 'compose', false);

    if (this.env.contentframe && window.frames && window.frames[this.env.contentframe]) {
        add_url = '&_framed=1';
        target = window.frames[this.env.contentframe];
        this.show_contentframe(true);
    }
    else if (framed)
        return false;

    if (action) {
        this.lock_frame();
        this.location_href(this.env.comm_path+'&_action=plugin.book&_act='+action
            +'&_source='+urlencode(this.book_realname())
            +add_url, target);
    }

    return true;
};

// submits book create/update form
rcube_webmail.prototype.book_save = function()
{
    var form = this.gui_objects.editform,
        input = $("input[name='_name']", form)

    if (input.length && input.val() == '') {
        alert(this.get_label('kolab_addressbook.nobooknamewarning'));
        input.focus();
        return;
    }

    input = this.display_message(this.get_label('kolab_addressbook.booksaving'), 'loading');
    $('<input type="hidden" name="_unlock" />').val(input).appendTo(form);

    form.submit();
};

// action executed after book delete
rcube_webmail.prototype.book_delete_done = function(id)
{
    var n, g, li = this.get_folder_li(id), groups = this.env.contactgroups;

    // remove folder and its groups rows
    for (n in groups)
        if (groups[n].source == id && (g = this.get_folder_li(n))) {
            $(g).remove();
            delete this.env.contactgroups[n];
        }
    $(li).remove();

    delete this.env.address_sources[id];
    delete this.env.contactfolders[id];
};

// action executed after book create/update
rcube_webmail.prototype.book_update = function(data, old)
{
    var n, i, id, len, link, row, refrow, olddata, name = '', realname = '', sources, level,
        folders = [], class_name = 'addressbook',
        list = this.gui_objects.folderlist,
        groups = this.env.contactgroups;

    this.env.contactfolders[data.id] = this.env.address_sources[data.id] = data;
    this.show_contentframe(false);

    // update
    if (old && old != data.id) {
        olddata = this.env.address_sources[old];
        delete this.env.address_sources[old];
        delete this.env.contactfolders[old];
        refrow = $('#rcmli'+old);
    }

    sources = this.env.address_sources;

    // set row attributes
    if (data.readonly)
        class_name += ' readonly';
    if (data.class_name)
        class_name += ' '+data.class_name;
    // updated currently selected book
    if (this.env.source != '' && this.env.source == old) {
        class_name += ' selected';
        this.env.source = data.id;
    }

    link = $('<a>').attr({'href': '#', 'rel': data.id}).html(data.name)
        .click(function() { return rcmail.command('list', data.id, this); });
    row = $('<li>').attr({id: 'rcmli'+data.id, 'class': class_name})
        .append(link);

    // sort kolab folders, to put the new one in order
    for (n in sources)
        if (sources[n].kolab && (name = sources[n].realname))
            folders.push(name);
    folders.sort();

    // find current id
    for (n=0, len=folders.length; n<len; n++)
        if (folders[n] == data.realname)
           break;

    // add row
    if (n && n < len) {
        // find the row before
        name = folders[n-1];
        for (n in sources)
            if (sources[n].realname && sources[n].realname == name) {
                // folder row found
                n = $('#rcmli'+n);
                // skip groups
                while (n.next().hasClass('contactgroup'))
                    n = n.next();
                row.insertAfter(n);
                break;
            }
    }
    else if (olddata) {
        row.insertBefore(refrow);
    }
    else {
        row.appendTo(list);
    }

    if (olddata) {
        // remove old row (just after the new row has been inserted)
        refrow.remove();

        // update groups
        for (n in groups) {
            if (groups[n].source == old) {
                // update existing row
                refrow = $('#rcmli'+n);
                refrow.remove().attr({id: 'rcmliG'+data.id+groups[n].id});
                $('a', refrow).removeAttr('onclick').unbind()
                    .click({source: data.id, id: groups[n].id}, function(e) {
                        return rcmail.command('listgroup', {'source': e.data.source, 'id': e.data.id}, this);
                    });
                refrow.insertAfter(row);
                row = refrow;

                // update group data
                groups[n].source = data.id;
                this.env.contactgroups['G'+data.id+groups[n].id] = groups[n];
                delete this.env.contactgroups[n];
            }
        }

        old += '-';
        level = olddata.realname.split(this.env.delimiter).length - data.realname.split(this.env.delimiter).length;
        // update (realname and ID of) subfolders
        for (n in sources) {
            if (n != data.id && n.indexOf(old) == 0) {
                // new ID
                id = data.id + '-' + n.substr(old.length);
                name = sources[n].name;
                realname = data.realname + sources[n].realname.substr(olddata.realname.length);

                // update display name
                if (level > 0) {
                    for (i=level; i>0; i--)
                        name = name.replace(/^&nbsp;&nbsp;/, '');
                }
                else if (level < 0) {
                    for (i=level; i<0; i++)
                        name = '&nbsp;&nbsp;' + name;
                }

                // update existing row
                refrow = $('#rcmli'+n);
                refrow.remove().attr({id: 'rcmli'+id});
                $('a', refrow).html(name).removeAttr('onclick').unbind().attr({rel: id, href: '#'})
                    .click({id: id}, function(e) { return rcmail.command('list', e.data.id, this); });

                // move the row to the new place
                refrow.insertAfter(row);
                row = refrow;

                // update list data
                sources[n].id = id;
                sources[n].name = name;
                sources[n].realname = realname;
                this.env.address_sources[id] = this.env.contactfolders[id] = sources[n];
                delete this.env.address_sources[n];
                delete this.env.contactfolders[n];

                // update groups
                for (i in groups) {
                    if (groups[i].source == n) {
                        // update existing row
                        refrow = $('#rcmli'+i);
                        refrow.remove().attr({id: 'rcmliG'+id+groups[i].id});
                        $('a', refrow).removeAttr('onclick').unbind()
                            .click({source: id, id: groups[i].id}, function(e) {
                                return rcmail.command('listgroup', {'source': e.data.source, 'id': e.data.id}, this);
                            });
                        refrow.insertAfter(row);
                        row = refrow;

                        // update group data
                        groups[i].source = id;
                        this.env.contactgroups['G'+id+groups[i].id] = groups[i];
                        delete this.env.contactgroups[i];
                    }
                }
            }
        }
    }
};

// returns real IMAP folder name
rcube_webmail.prototype.book_realname = function()
{
    var source = this.env.source, sources = this.env.address_sources;
    return source != '' && sources[source] && sources[source].realname ? sources[source].realname : '';
};
