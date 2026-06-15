jQuery(document).ready(function($) {
    // Add some interactivity if needed
    $('.itea-adserver-settings .cap-check').on('click', function(e) {
        if (e.target.tagName !== 'INPUT') {
            $(this).find('input[type="checkbox"]').trigger('click');
        }
    });

    // Toggle Ad Status
    $(document).on('click', '.wp-ad-status-toggle', function() {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        var nonce = $btn.data('nonce');

        $btn.css('opacity', '0.5');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'itea_adserver_toggle_status',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $btn.html(response.data.html);
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert('Something went wrong. Please try again.');
            },
            complete: function() {
                $btn.css('opacity', '1');
            }
        });
    });

    // Shortcode Modal
    var $modal = $('#itea-adserver-modal');
    var $modalClose = $('.itea-modal-close');

    $(document).on('click', '.itea-adserver-get-shortcode', function(e) {
        e.preventDefault();
        var $el = $(this);
        var type = $el.data('type') || 'ad';
        var phpShortcode = '';
        var jsTag = '';

        if (type === 'ad') {
            var adId = $el.data('id');
            phpShortcode = '[itea_ad id="' + adId + '"]';
            jsTag = '<div class="itea-adserver-placeholder" data-ad-id="' + adId + '"></div>';
        } else {
            var slug = $el.data('slug');
            var uid = 'itea-ad-' + slug;
            var baseUrl = (typeof iteaAdminData !== 'undefined') ? iteaAdminData.homeUrl : (window.location.origin + '/');
            
            phpShortcode = '[itea_adserver zone="' + slug + '"]';
            jsTag = '<script src="' + baseUrl + '?itea_ad_serve=1&zone=' + slug + '&uid=' + uid + '" async></script>';
        }
        
        $('#itea-shortcode-php').text(phpShortcode);
        $('#itea-shortcode-js').text(jsTag);
        
        $modal.show();
    });

    $modalClose.on('click', function() {
        $modal.hide();
    });

    $(window).on('click', function(e) {
        if ($(e.target).is($modal)) {
            $modal.hide();
        }
    });

    // Copy to Clipboard
    $('.itea-copy-btn').on('click', function() {
        var targetId = $(this).data('target');
        var text = $('#' + targetId).text();
        var $btn = $(this);
        var originalText = $btn.text();

        navigator.clipboard.writeText(text).then(function() {
            $btn.text('Copied!');
            setTimeout(function() {
                $btn.text(originalText);
            }, 2000);
        }).catch(function(err) {
            console.error('Could not copy text: ', err);
            // Fallback for older browsers
            var textArea = document.createElement("textarea");
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                $btn.text('Copied!');
                setTimeout(function() {
                    $btn.text(originalText);
                }, 2000);
            } catch (err) {
                console.error('Fallback: Oops, unable to copy', err);
            }
            document.body.removeChild(textArea);
        });
    });
});
