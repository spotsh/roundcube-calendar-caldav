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
rcube_webmail.prototype.book_delete_done = function(id, recur)
{
    var n, groups = this.env.contactgroups,
        sources = this.env.address_sources,
        olddata = sources[id];
alert(id);
    this.treelist.remove(id);

    for (n in groups)
        if (groups[n].source == id) {
            delete this.env.contactgroups[n];
            delete this.env.contactfolders[n];
        }

    delete this.env.address_sources[id];
    delete this.env.contactfolders[id];

    if (recur)
        return;

    this.enable_command('group-create', 'book-edit', 'book-delete', false);

    // remove subfolders
    olddata.realname += this.env.delimiter;
alert(olddata.realname)
    for (n in sources)
        if (sources[n].realname && sources[n].realname.indexOf(olddata.realname) == 0)
            this.book_delete_done(n, true);
};

// action executed after book create/update
rcube_webmail.prototype.book_update = function(data, old, recur)
{
    var n, i, id, len, link, row, prop, olddata, oldid, name, sources, level,
        folders = [], classes = ['addressbook'],
        groups = this.env.contactgroups;

    this.env.contactfolders[data.id] = this.env.address_sources[data.id] = data;
    this.show_contentframe(false);

    // update (remove old row)
    if (old && old != data.id) {
        olddata = this.env.address_sources[old];
        delete this.env.address_sources[old];
        delete this.env.contactfolders[old];
        this.treelist.remove(old);
    }

    sources = this.env.address_sources;

    // set row attributes
    if (data.readonly)
        classes.push('readonly');
    if (data.class_name)
        classes.push(data.class_name);
    // updated currently selected book
    if (this.env.source != '' && this.env.source == old) {
        classes.push('selected');
        this.env.source = data.id;
    }

    link = $('<a>').html(data.name)
      .attr({
        href: '#', rel: data.id,
        onclick: "return rcmail.command('list', '" + data.id + "', this)"
      });

    // add row at the end of the list
    // treelist widget is not very smart, we need
    // to do sorting and add groups list by ourselves
    this.treelist.insert({id: data.id, html:link, classes: classes, childlistclass: 'groups'}, '', false);
    row = $(this.treelist.get_item(data.id));
    row.append($('<ul class="groups">').hide());

    // we need to sort rows because treelist can't sort by property
    $.each(sources, function(i, v) {
        if (v.kolab && v.realname)
            folders.push(v.realname);
    });
    folders.sort();

    for (n=0, len=folders.length; n<len; n++)
        if (folders[n] == data.realname)
           break;

    // find the row before and re-insert after it
    if (n && n < len - 1) {
        name = folders[n-1];
        for (n in sources)
            if (sources[n].realname && sources[n].realname == name) {
                row.detach().insertAfter(this.treelist.get_item(n));
                break;
            }
    }

    if (olddata) {
        // update groups
        for (n in groups) {
            if (groups[n].source == old) {
                prop = groups[n];
                prop.type = 'group';
                prop.source = data.id;
                id = 'G' + prop.source + prop.id;

                link = $('<a>').text(prop.name)
                  .attr({
                    href: '#', rel: prop.source + ':' + prop.id,
                    onclick: "return rcmail.command('listgroup', {source: '"+prop.source+"', id: '"+prop.id+"'}, this)"
                  });

                this.treelist.insert({id:id, html:link, classes:['contactgroup']}, prop.source, true);

                this.env.contactfolders[id] = this.env.contactgroups[id] = prop;
                delete this.env.contactgroups[n];
                delete this.env.contactfolders[n];
            }
        }

        if (recur)
            return;

        // update subfolders
        old += '_';
        level = olddata.realname.split(this.env.delimiter).length - data.realname.split(this.env.delimiter).length;
        olddata.realname += this.env.delimiter;

        for (n in sources) {
            if (sources[n].realname && sources[n].realname.indexOf(olddata.realname) == 0) {
                prop = sources[n];
                oldid = sources[n].id;
                // new ID
                prop.id = data.id + '_' + n.substr(old.length);
                prop.realname = data.realname + prop.realname.substr(olddata.realname.length - 1);
                name = prop.name;

                // update display name
                if (level > 0) {
                    for (i=level; i>0; i--)
                        name = name.replace(/^&nbsp;&nbsp;/, '');
                }
                else if (level < 0) {
                    for (i=level; i<0; i++)
                        name = '&nbsp;&nbsp;' + name;
                }

                prop.name = name;
                this.book_update(prop, oldid, true)
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
