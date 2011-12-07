/**
 * Client script for the Kolab folder management/listing extension
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
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
