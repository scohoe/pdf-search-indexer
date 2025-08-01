jQuery(document).ready(function($) {
    // Only run this on the PDF Search Indexer settings page
    if ($('body.settings_page_pdf-search-indexer').length === 0) {
        return;
    }

    function updateStatus() {
        $.ajax({
            url: pdfIndexer.ajax_url,
            type: 'POST',
            data: {
                action: 'pdf_search_indexer_get_status',
                nonce: pdfIndexer.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var progress = data.progress;

                    // Update progress bar
                    var indexed = parseInt(progress.processed_count) || 0;
                    var total = parseInt(progress.total_count) || 0;
                    var percentage = total > 0 ? Math.round((indexed / total) * 100) : 0;
                    $('.pdf-indexer-progress-container .progress-bar').css('width', percentage + '%');
                    $('.pdf-indexer-progress-container .progress-text').text(percentage + '% Complete');

                    // Update stats
                    $('#total-pdfs').text(total);
                    $('#indexed-pdfs').text(indexed);
                    // You can add more stats here if needed

                    // Update currently processing file
                    $('#currently-processing').text(progress.current_file || 'None');

                    // Update processing log
                    var logHtml = '';
                    if (progress.log && progress.log.length > 0) {
                        progress.log.forEach(function(item) {
                            var statusClass = item.status === 'completed' ? 'status-completed' : 'status-secured';
                            logHtml += '<li><strong>' + item.file + '</strong><br><span class="' + statusClass + '">' + item.status + '</span> - ' + item.timestamp + '</li>';
                        });
                    } else {
                        logHtml = '<li>No processing activity yet.</li>';
                    }
                    $('#processing-log').html(logHtml);

                    // Update error log
                    var errorHtml = '';
                    if (progress.errors && progress.errors.length > 0) {
                        progress.errors.forEach(function(item) {
                            errorHtml += '<li><strong>' + item.file + '</strong><br><span class="status-error">' + item.error + '</span> - ' + item.timestamp + '<br><small>' + item.message + '</small></li>';
                        });
                    } else {
                        errorHtml = '<li>No errors recorded.</li>';
                    }
                    $('#error-log').html(errorHtml);

                    // Update process status
                    $('#process-status').text(data.process_status);
                }
            },
            error: function() {
                console.log('Error fetching PDF indexer status.');
            }
        });
    }

    // Poll every 5 seconds
    setInterval(updateStatus, 5000);

    // Initial call
    updateStatus();
});