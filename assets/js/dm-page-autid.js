jQuery(function($){

    // --- DataTables custom filter: uses row data-* attributes ---
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {

        var api  = new $.fn.dataTable.Api(settings);
        var row  = api.row(dataIndex).node();
        var $row = $(row);

        var starFilter     = $('#dm-filter-star').val();
        var builderFilter  = $('#dm-filter-builder').val();
        var menuFilter     = $('#dm-filter-menu').val();
        var seoFilter      = $('#dm-filter-seo').val();
        var wordsFilter    = $('#dm-filter-words').val();
        var parentFilter   = $('#dm-filter-parent').val();
        var templateFilter = $('#dm-filter-template').val();

        var star     = $row.data('star');     // 0/1
        var builder  = $row.data('builder');  // string
        var menu     = $row.data('menu');     // Yes/No
        var seo      = $row.data('seo');      // ok/missing_title/...
        var words    = parseInt($row.data('words'), 10);
        var parent   = $row.data('parent');   // string
        var template = $row.data('template'); // string

        // Star filter
        if (starFilter === 'starred' && !star) return false;
        if (starFilter === 'unstarred' && star) return false;

        // Builder filter
        if (builderFilter !== 'all' && builderFilter !== builder) return false;

        // Menu filter
        if (menuFilter !== 'all' && menuFilter !== menu) return false;

        // SEO filter
        if (seoFilter !== 'all' && seoFilter !== seo) return false;

        // Words filter
        if (wordsFilter === 'thin' && words >= 200) return false;
        if (wordsFilter === 'short' && (words < 200 || words > 800)) return false;
        if (wordsFilter === 'medium' && (words < 800 || words > 1500)) return false;
        if (wordsFilter === 'long' && words <= 1500) return false;

        // Parent filter
        if (parentFilter !== 'all' && parentFilter !== parent) return false;

        // Template filter
        if (templateFilter !== 'all' && templateFilter !== template) return false;

        return true;
    });

    // --- Initialize DataTable ---
    var table = $('.dm-audit-table').DataTable({
        paging: false,
        info: false,
        scrollX: true,
        order: [[1, 'asc']], // sort by Title
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'colvis',
                text: 'Columns'
            },
            {
                extend: 'csvHtml5',
                text: 'Export CSV',
                title: 'page-audit'
            }
        ],
        columnDefs: [
            { targets: [0, 19], orderable: false } // star + notes
        ]
    });

    // --- Column group toggles ---
    var columnGroups = {
        content:   [1, 3, 4, 5, 8, 13],       // Title, Slug, Excerpt, Words, Author, Featured
        seo:       [14, 15, 16, 17],          // Redirect, SEO title, Meta, Internal links
        structure: [9, 10, 11, 12],           // Parent, Menu, Template, Builder
        dates:     [6, 7],                    // Published, Updated
        audit:     [0, 18, 19]                // Star, Traffic, Notes
    };

    function setGroupVisibility(group, visible) {
        var cols = columnGroups[group] || [];
        cols.forEach(function(idx) {
            table.column(idx).visible(visible);
        });
    }

    $('.dm-group-toggle').on('change', function() {
        var group   = $(this).data('group');
        var visible = $(this).is(':checked');
        setGroupVisibility(group, visible);
    });

    // --- Filters: redraw on change ---
    $('#dm-filter-star, #dm-filter-builder, #dm-filter-menu, #dm-filter-seo, #dm-filter-words, #dm-filter-parent, #dm-filter-template')
        .on('change', function() {
            table.draw();
        });

    // --- AJAX: Save notes on blur ---
    $('.dm-audit-table').on('blur', '.dm-note', function() {
        var $textarea = $(this);
        var $row      = $textarea.closest('tr');
        var pageId    = $row.data('page');
        var note      = $textarea.val();

        $.post(dmPageAudit.ajaxUrl, {
            action: 'dm_save_audit_note',
            page: pageId,
            note: note,
            nonce: dmPageAudit.nonce
        });
    });

    // --- AJAX: Save traffic indicator on blur ---
    $('.dm-audit-table').on('blur', '.dm-traffic', function() {
        var $span   = $(this);
        var $row    = $span.closest('tr');
        var pageId  = $row.data('page');
        var traffic = $span.text();

        $.post(dmPageAudit.ajaxUrl, {
            action: 'dm_save_audit_traffic',
            page: pageId,
            traffic: traffic,
            nonce: dmPageAudit.nonce
        });
    });

    // --- AJAX: Toggle star ---
    $('.dm-audit-table').on('click', '.dm-star', function() {
        var $star = $(this);
        var $row  = $star.closest('tr');
        var pageId = $row.data('page');
        var keep   = $star.hasClass('active') ? 0 : 1;

        $.post(dmPageAudit.ajaxUrl, {
            action: 'dm_toggle_audit_star',
            page: pageId,
            keep: keep,
            nonce: dmPageAudit.nonce
        });

        $star.toggleClass('active');
        $row.data('star', keep);

        table.draw(); // re-apply star filter if active
    });

});
