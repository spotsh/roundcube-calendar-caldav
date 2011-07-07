$(document).ready(function() {
    // IE doesn't allow setting OPTION's display/visibility
    // We'll need to remove SELECT's options, see below
    if (bw.ie) {
        rcmail.env.subtype_html = $('#_subtype').html();
    }

    // Add onchange handler for folder type SELECT, and call it on form init
    $('#_ctype').change(function() {
        var type = $(this).val(),
            sub = $('#_subtype'),
            subtype = sub.val();

        // For IE we need to revert the whole SELECT to the original state
        if (bw.ie) {
            sub.html(rcmail.env.subtype_html).val(subtype);
        }

        // For non-mail folders we must hide mail-specific subtypes
        $('option', sub).each(function() {
            var opt = $(this), val = opt.val();
            if (val == '')
                return;
            // there's no mail.default
            if (val == 'default' && type != 'mail') {
                opt.show();
                return;
            };

            if (type == 'mail' && val != 'default')
                opt.show();
            else if (bw.ie)
                opt.remove();
            else
                opt.hide();
        });

        // And re-set subtype
        if (type != 'mail' && subtype != '' && subtype != 'default') {
            sub.val('');
        }
    }).change();
});
