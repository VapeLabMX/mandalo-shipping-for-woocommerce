/**
 * Mandalo Shipping - Checkout JavaScript v2.0
 * Complete UI for shipping selection with autocomplete, multi-address, and real-time pricing
 */
(function($) {
    'use strict';

    var MandaloCheckout = {
        stops: [],
        currentType: 'standard',
        originAddress: '',
        debounceTimer: null,
        autocompleteTimer: null,
        osrmEndpoint: 'http://31.97.98.61:5001',
        currentQuote: null,

        /**
         * Initialize the checkout module
         */
        init: function() {
            var self = this;

            // Wait for DOM ready
            $(document).ready(function() {
                self.bindEvents();
                self.initShippingTypeSelector();
                self.initOriginSection();

                // Check initial shipping method
                var $checked = $('input[name="shipping_method[0]"]:checked');
                if ($checked.length && $checked.val().indexOf('mandalo_shipping') !== -1) {
                    self.onShippingMethodChange($checked.val());
                }
            });
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function() {
            var self = this;

            // Shipping type card selection
            $(document).on('click', '.mandalo-shipping-type-card', function(e) {
                if ($(e.target).is('input')) return;
                var $radio = $(this).find('input[type="radio"]');
                $radio.prop('checked', true).trigger('change');
            });

            // Shipping type change
            $(document).on('change', '.mandalo-shipping-type-card input[type="radio"]', function() {
                self.onShippingTypeChange($(this).val());
            });

            // Legacy shipping method change (from WC)
            $(document).on('change', 'input[name="shipping_method[0]"]', function() {
                self.onShippingMethodChange($(this).val());
            });

            // Origin address change
            $(document).on('input', '#mandalo_origin_address', function() {
                self.onOriginAddressChange($(this).val());
            });

            // Origin type toggle
            $(document).on('change', 'input[name="mandalo_origin_type"]', function() {
                self.onOriginTypeChange($(this).val());
            });

            // Add stop button
            $(document).on('click', '#mandalo-add-stop', function(e) {
                e.preventDefault();
                self.addStop();
            });

            // Remove stop button
            $(document).on('click', '.mandalo-remove-stop', function(e) {
                e.preventDefault();
                $(this).closest('.mandalo-stop').remove();
                self.updateStopNumbers();
                self.calculateRoute();
            });

            // Stop address input with autocomplete
            $(document).on('input', '.mandalo-stop-address', function() {
                var $input = $(this);
                clearTimeout(self.autocompleteTimer);
                self.autocompleteTimer = setTimeout(function() {
                    self.showAutocomplete($input);
                }, 300);
            });

            // Stop address blur - trigger route calculation
            $(document).on('blur', '.mandalo-stop-address', function() {
                var self = MandaloCheckout;
                setTimeout(function() {
                    self.hideAllAutocomplete();
                    self.calculateRoute();
                }, 200);
            });

            // Autocomplete item click
            $(document).on('click', '.mandalo-autocomplete-item', function() {
                var $item = $(this);
                var $wrapper = $item.closest('.mandalo-stop-address-wrapper, .mandalo-origin-address-input');
                var $input = $wrapper.find('input');
                $input.val($item.data('address'));
                self.hideAllAutocomplete();
                self.calculateRoute();
            });

            // Optimize route toggle
            $(document).on('change', '#mandalo_optimize_route', function() {
                self.calculateRoute();
            });

            // Scheduled date change
            $(document).on('change', '#mandalo_scheduled_date', function() {
                self.loadTimeSlots($(this).val());
            });

            // Vehicle dimensions change
            $(document).on('input', '#mandalo_package_weight, #mandalo_package_length, #mandalo_package_width, #mandalo_package_height', function() {
                clearTimeout(self.debounceTimer);
                self.debounceTimer = setTimeout(function() {
                    self.validateVehicle();
                }, 400);
            });

            // Checkout update (WC)
            $(document.body).on('updated_checkout', function() {
                self.onCheckoutUpdated();
            });

            // Close autocomplete on outside click
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.mandalo-stop-address-wrapper, .mandalo-origin-address-input').length) {
                    self.hideAllAutocomplete();
                }
            });

            // Drag and drop for stops
            this.initDragAndDrop();
        },

        /**
         * Initialize origin section
         */
        initOriginSection: function() {
            // Set default origin type based on what's available
            var $storeOption = $('input[name="mandalo_origin_type"][value="store"]');
            if ($storeOption.length) {
                $storeOption.prop('checked', true);
            }
        },

        /**
         * Initialize shipping type selector
         */
        initShippingTypeSelector: function() {
            var $container = $('#mandalo-shipping-type-selector');
            if (!$container.length) return;

            // Check for selected card
            var $selectedCard = $container.find('.mandalo-shipping-type-card.selected');
            if ($selectedCard.length) {
                var type = $selectedCard.find('input[type="radio"]').val();
                this.onShippingTypeChange(type);
            }
        },

        /**
         * Handle origin type change
         */
        onOriginTypeChange: function(type) {
            var $customInput = $('#mandalo-origin-custom-address');
            if (type === 'custom') {
                $customInput.slideDown(200);
                $('#mandalo_origin_address').focus();
            } else {
                $customInput.slideUp(200);
                this.originAddress = ''; // Will use store address
            }
            this.calculateRoute();
        },

        /**
         * Handle origin address change
         */
        onOriginAddressChange: function(address) {
            var self = this;
            this.originAddress = address;

            clearTimeout(this.autocompleteTimer);
            this.autocompleteTimer = setTimeout(function() {
                if (address.length >= 3) {
                    self.showOriginAutocomplete(address);
                }
            }, 300);

            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(function() {
                self.calculateRoute();
            }, 800);
        },

        /**
         * Show origin autocomplete
         */
        showOriginAutocomplete: function(query) {
            var self = this;
            var $input = $('#mandalo_origin_address');
            var $wrapper = $input.closest('.mandalo-origin-address-input');

            this.geocodeAddress(query, function(results) {
                self.renderAutocomplete($wrapper, results);
            });
        },

        /**
         * Handle shipping type change (our custom cards)
         */
        onShippingTypeChange: function(type) {
            var self = this;
            this.currentType = type;

            // Update card selection
            $('.mandalo-shipping-type-card').removeClass('selected');
            $('.mandalo-shipping-type-card input[value="' + type + '"]').closest('.mandalo-shipping-type-card').addClass('selected');

            // Hide all option sections first
            $('#mandalo-multi-address-wrapper').hide();
            $('#mandalo-scheduling-wrapper').hide();
            $('#mandalo-express-wrapper').hide();
            $('#mandalo-vehicle-wrapper').hide();

            // Show appropriate section
            switch (type) {
                case 'multi_optimized':
                case 'multi_ordered':
                    $('#mandalo-multi-address-wrapper').slideDown(300);
                    if ($('#mandalo-stops-container .mandalo-stop').length === 0) {
                        this.addStop();
                    }
                    if (type === 'multi_optimized') {
                        $('#mandalo_optimize_route').prop('checked', true);
                    } else {
                        $('#mandalo_optimize_route').prop('checked', false);
                    }
                    $('#mandalo-route-options').show();
                    break;

                case 'express':
                    $('#mandalo-express-wrapper').slideDown(300);
                    this.checkExpressAvailability();
                    break;

                case 'scheduled':
                    $('#mandalo-scheduling-wrapper').slideDown(300);
                    break;

                case 'truck':
                    $('#mandalo-vehicle-wrapper').slideDown(300);
                    break;
            }

            // Update hidden input for form submission
            this.updateHiddenInputs();

            // Recalculate quote
            this.calculateRoute();
        },

        /**
         * Handle WC shipping method change
         */
        onShippingMethodChange: function(methodId) {
            if (!methodId || methodId.indexOf('mandalo_shipping') === -1) {
                $('#mandalo-shipping-container').hide();
                return;
            }

            $('#mandalo-shipping-container').show();

            // Extract type from method ID (e.g., mandalo_shipping:1_express)
            var parts = methodId.split('_');
            var type = parts[parts.length - 1];

            // Select the corresponding card
            var $card = $('.mandalo-shipping-type-card input[value="' + type + '"]');
            if ($card.length) {
                $card.prop('checked', true).trigger('change');
            }
        },

        /**
         * Add a new stop
         */
        addStop: function() {
            var index = $('#mandalo-stops-container .mandalo-stop').length;
            var template = this.getStopTemplate(index);

            $('#mandalo-stops-container').append(template);
            this.updateStopNumbers();

            // Focus new input
            $('#mandalo-stops-container .mandalo-stop:last .mandalo-stop-address').focus();
        },

        /**
         * Get stop HTML template
         */
        getStopTemplate: function(index) {
            return '<div class="mandalo-stop" data-index="' + index + '" draggable="true">' +
                '<div class="mandalo-stop-header">' +
                    '<div class="mandalo-stop-number">' +
                        '<span class="number-badge">' + (index + 1) + '</span>' +
                        '<span>' + MandaloShipping.i18n.stop_label + '</span>' +
                    '</div>' +
                    '<div class="mandalo-stop-actions">' +
                        '<button type="button" class="mandalo-drag-handle" title="' + MandaloShipping.i18n.drag_to_reorder + '">' +
                            '<svg viewBox="0 0 24 24"><path d="M11 18c0 1.1-.9 2-2 2s-2-.9-2-2 .9-2 2-2 2 .9 2 2zm-2-8c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0-6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm6 4c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>' +
                        '</button>' +
                        '<button type="button" class="mandalo-remove-stop" title="' + MandaloShipping.i18n.remove_stop + '">&times;</button>' +
                    '</div>' +
                '</div>' +
                '<div class="mandalo-stop-address-wrapper">' +
                    '<input type="text" class="input-text mandalo-stop-address" name="mandalo_stops[]" placeholder="' + MandaloShipping.i18n.address_placeholder + '" autocomplete="off">' +
                '</div>' +
            '</div>';
        },

        /**
         * Update stop numbers after reorder/delete
         */
        updateStopNumbers: function() {
            $('#mandalo-stops-container .mandalo-stop').each(function(index) {
                $(this).attr('data-index', index);
                $(this).find('.number-badge').text(index + 1);
            });

            // Show/hide route options based on stop count
            var stopCount = $('#mandalo-stops-container .mandalo-stop').length;
            if (stopCount >= 2) {
                $('#mandalo-route-options').slideDown(200);
            } else {
                $('#mandalo-route-options').slideUp(200);
            }
        },

        /**
         * Initialize drag and drop
         */
        initDragAndDrop: function() {
            var self = this;

            $(document).on('dragstart', '.mandalo-stop', function(e) {
                $(this).addClass('dragging');
                e.originalEvent.dataTransfer.setData('text/plain', $(this).data('index'));
            });

            $(document).on('dragend', '.mandalo-stop', function() {
                $(this).removeClass('dragging');
            });

            $(document).on('dragover', '.mandalo-stop', function(e) {
                e.preventDefault();
                var $dragging = $('.mandalo-stop.dragging');
                var $this = $(this);
                if ($dragging[0] !== $this[0]) {
                    var rect = $this[0].getBoundingClientRect();
                    var midpoint = rect.top + rect.height / 2;
                    if (e.originalEvent.clientY < midpoint) {
                        $this.before($dragging);
                    } else {
                        $this.after($dragging);
                    }
                }
            });

            $(document).on('drop', '.mandalo-stop', function(e) {
                e.preventDefault();
                self.updateStopNumbers();
                self.calculateRoute();
            });
        },

        /**
         * Show autocomplete dropdown
         */
        showAutocomplete: function($input) {
            var self = this;
            var query = $input.val().trim();

            if (query.length < 3) {
                this.hideAutocomplete($input);
                return;
            }

            var $wrapper = $input.closest('.mandalo-stop-address-wrapper');

            this.geocodeAddress(query, function(results) {
                self.renderAutocomplete($wrapper, results);
            });
        },

        /**
         * Geocode address using Nominatim
         */
        geocodeAddress: function(query, callback) {
            // Add CDMX/Mexico context to improve results
            var searchQuery = query + ', Ciudad de Mexico, Mexico';

            $.ajax({
                url: 'https://nominatim.openstreetmap.org/search',
                type: 'GET',
                dataType: 'json',
                data: {
                    q: searchQuery,
                    format: 'json',
                    limit: 5,
                    countrycodes: 'mx',
                    'accept-language': 'es'
                },
                headers: {
                    'User-Agent': 'MandaloShipping-WooCommerce/2.0'
                },
                success: function(data) {
                    var results = data.map(function(item) {
                        return {
                            address: item.display_name,
                            lat: parseFloat(item.lat),
                            lon: parseFloat(item.lon),
                            shortAddress: self.formatShortAddress(item.display_name)
                        };
                    });
                    callback(results);
                },
                error: function() {
                    callback([]);
                }
            });

            var self = this;
        },

        /**
         * Format short address for display
         */
        formatShortAddress: function(fullAddress) {
            var parts = fullAddress.split(',');
            if (parts.length > 3) {
                return parts.slice(0, 3).join(',');
            }
            return fullAddress;
        },

        /**
         * Render autocomplete dropdown
         */
        renderAutocomplete: function($wrapper, results) {
            // Remove existing dropdown
            $wrapper.find('.mandalo-autocomplete-dropdown').remove();

            if (results.length === 0) return;

            var html = '<div class="mandalo-autocomplete-dropdown">';
            results.forEach(function(item) {
                html += '<div class="mandalo-autocomplete-item" data-address="' + item.address.replace(/"/g, '&quot;') + '" data-lat="' + item.lat + '" data-lon="' + item.lon + '">';
                html += '<strong>' + item.shortAddress + '</strong>';
                html += '<small>' + item.address + '</small>';
                html += '</div>';
            });
            html += '</div>';

            $wrapper.append(html);
        },

        /**
         * Hide autocomplete for specific input
         */
        hideAutocomplete: function($input) {
            $input.closest('.mandalo-stop-address-wrapper, .mandalo-origin-address-input').find('.mandalo-autocomplete-dropdown').remove();
        },

        /**
         * Hide all autocomplete dropdowns
         */
        hideAllAutocomplete: function() {
            $('.mandalo-autocomplete-dropdown').remove();
        },

        /**
         * Calculate route and update quote
         */
        calculateRoute: function() {
            var self = this;
            var stops = [];

            // Collect all stop addresses
            $('#mandalo-stops-container .mandalo-stop-address').each(function() {
                var val = $(this).val().trim();
                if (val) {
                    stops.push(val);
                }
            });

            // For standard shipping, we just need destination
            if (this.currentType === 'standard' || stops.length === 0) {
                // Get WC shipping destination
                var dest = this.getWCDestination();
                if (dest) {
                    stops = [dest];
                } else {
                    this.hideQuoteSummary();
                    return;
                }
            }

            // Need at least origin and 1 destination
            if (stops.length < 1) {
                $('#mandalo-route-preview').hide();
                this.hideQuoteSummary();
                return;
            }

            var optimize = $('#mandalo_optimize_route').is(':checked');

            // Show loading
            $('#mandalo-route-preview').addClass('mandalo-loading').show();
            this.showQuoteLoading();

            $.ajax({
                url: MandaloShipping.ajax_url,
                type: 'POST',
                data: {
                    action: 'mandalo_calculate_route',
                    nonce: MandaloShipping.nonce,
                    stops: stops,
                    origin: this.originAddress,
                    optimize: optimize,
                    shipping_type: this.currentType
                },
                success: function(response) {
                    $('#mandalo-route-preview').removeClass('mandalo-loading');

                    if (response.success) {
                        self.currentQuote = response.data;
                        self.renderRoutePreview(response.data, stops);
                        self.renderQuoteSummary(response.data);
                        self.updateHiddenInputs(response.data);

                        // Trigger checkout update for price sync
                        $(document.body).trigger('update_checkout');
                    } else {
                        self.showQuoteError(response.data.message || MandaloShipping.i18n.route_error);
                    }
                },
                error: function() {
                    $('#mandalo-route-preview').removeClass('mandalo-loading');
                    self.showQuoteError(MandaloShipping.i18n.connection_error);
                }
            });
        },

        /**
         * Get WooCommerce destination address
         */
        getWCDestination: function() {
            var parts = [
                $('#shipping_address_1, #billing_address_1').first().val(),
                $('#shipping_address_2, #billing_address_2').first().val(),
                $('#shipping_city, #billing_city').first().val(),
                $('#shipping_state, #billing_state').first().val(),
                $('#shipping_postcode, #billing_postcode').first().val()
            ].filter(function(p) { return p && p.trim(); });

            return parts.length > 0 ? parts.join(', ') : null;
        },

        /**
         * Render route preview
         */
        renderRoutePreview: function(data, stops) {
            var html = '<div id="mandalo-route-details">' +
                '<div class="mandalo-route-stat">' +
                    '<span class="mandalo-route-stat-label">' + MandaloShipping.i18n.distance + '</span>' +
                    '<span class="mandalo-route-stat-value">' + data.total_distance + ' km</span>' +
                '</div>' +
                '<div class="mandalo-route-stat">' +
                    '<span class="mandalo-route-stat-label">' + MandaloShipping.i18n.stops + '</span>' +
                    '<span class="mandalo-route-stat-value">' + stops.length + '</span>' +
                '</div>' +
                '<div class="mandalo-route-stat">' +
                    '<span class="mandalo-route-stat-label">' + MandaloShipping.i18n.estimated_time + '</span>' +
                    '<span class="mandalo-route-stat-value">' + this.formatDuration(data.duration || data.total_distance * 3) + '</span>' +
                '</div>' +
            '</div>';

            // Show optimized order if available
            if (data.optimized_order && data.optimized_order.length) {
                html += '<div id="mandalo-optimized-order">' +
                    '<h6>' + MandaloShipping.i18n.optimized_order + '</h6>' +
                    '<ol id="mandalo-optimized-list">';
                data.optimized_order.forEach(function(idx) {
                    if (stops[idx]) {
                        html += '<li>' + stops[idx].substring(0, 50) + (stops[idx].length > 50 ? '...' : '') + '</li>';
                    }
                });
                html += '</ol></div>';
            }

            $('#mandalo-route-preview').html('<h5>' + MandaloShipping.i18n.route_preview + '</h5>' + html).show();
        },

        /**
         * Format duration in minutes
         */
        formatDuration: function(minutes) {
            if (minutes < 60) {
                return Math.round(minutes) + ' min';
            }
            var hours = Math.floor(minutes / 60);
            var mins = Math.round(minutes % 60);
            return hours + 'h ' + mins + 'min';
        },

        /**
         * Render quote summary
         */
        renderQuoteSummary: function(data) {
            var $summary = $('#mandalo-quote-summary');
            if (!$summary.length) {
                // Create summary if doesn't exist
                $('#mandalo-shipping-container').append('<div id="mandalo-quote-summary"></div>');
                $summary = $('#mandalo-quote-summary');
            }

            var breakdown = data.breakdown || {};
            var html = '<h4>' + MandaloShipping.i18n.quote_title + ' <span class="quote-status">' + MandaloShipping.i18n.calculated + '</span></h4>';

            html += '<div class="mandalo-quote-breakdown">';

            // Base fee
            html += '<div class="mandalo-quote-row">' +
                '<span class="label">' + MandaloShipping.i18n.base_fee + '</span>' +
                '<span class="value">$' + (breakdown.base_fee || 45).toFixed(2) + ' MXN</span>' +
            '</div>';

            // Distance
            html += '<div class="mandalo-quote-row">' +
                '<span class="label">' + data.total_distance + ' km x $' + (breakdown.per_km_rate || 8) + '/km</span>' +
                '<span class="value">$' + (breakdown.distance_fee || (data.total_distance * 8)).toFixed(2) + ' MXN</span>' +
            '</div>';

            // Modifiers
            if (breakdown.modifiers && breakdown.modifiers.length) {
                breakdown.modifiers.forEach(function(mod) {
                    var rowClass = mod.amount < 0 ? 'mandalo-quote-row discount' : 'mandalo-quote-row';
                    html += '<div class="' + rowClass + '">' +
                        '<span class="label">' + mod.label + '</span>' +
                        '<span class="value">' + (mod.amount < 0 ? '-' : '+') + '$' + Math.abs(mod.amount).toFixed(2) + ' MXN</span>' +
                    '</div>';
                });
            }

            // Total
            html += '<div class="mandalo-quote-row total">' +
                '<span class="label">' + MandaloShipping.i18n.total + '</span>' +
                '<span class="value">$' + data.price.toFixed(2) + ' MXN</span>' +
            '</div>';

            html += '</div>';

            // ETA
            var eta = this.getETA();
            html += '<div class="mandalo-quote-eta">' +
                '<svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>' +
                '<span>' + MandaloShipping.i18n.delivery_eta + ': <strong>' + eta + '</strong></span>' +
            '</div>';

            $summary.html(html).show();
        },

        /**
         * Get ETA based on shipping type
         */
        getETA: function() {
            switch (this.currentType) {
                case 'express':
                    return MandaloShipping.i18n.today;
                case 'scheduled':
                    var date = $('#mandalo_scheduled_date').val();
                    var time = $('#mandalo_scheduled_time').val();
                    if (date) {
                        return date + (time ? ' ' + time : '');
                    }
                    return MandaloShipping.i18n.select_date;
                default:
                    return '1-2 ' + MandaloShipping.i18n.business_days;
            }
        },

        /**
         * Show quote loading state
         */
        showQuoteLoading: function() {
            var $summary = $('#mandalo-quote-summary');
            if (!$summary.length) {
                $('#mandalo-shipping-container').append('<div id="mandalo-quote-summary"></div>');
                $summary = $('#mandalo-quote-summary');
            }

            $summary.html('<h4>' + MandaloShipping.i18n.quote_title + '</h4><p class="mandalo-calculating">' + MandaloShipping.i18n.calculating + '</p>').show();
        },

        /**
         * Show quote error
         */
        showQuoteError: function(message) {
            var $summary = $('#mandalo-quote-summary');
            if ($summary.length) {
                $summary.html('<h4>' + MandaloShipping.i18n.quote_title + '</h4><p style="color: #dc3545;">' + message + '</p>');
            }
        },

        /**
         * Hide quote summary
         */
        hideQuoteSummary: function() {
            $('#mandalo-quote-summary').hide();
        },

        /**
         * Update hidden inputs for form submission
         */
        updateHiddenInputs: function(quoteData) {
            var $form = $('form.checkout');

            // Remove old hidden inputs
            $form.find('input[name^="mandalo_"]').not('.mandalo-stop-address, input[type="radio"], input[type="checkbox"], input[type="date"], input[type="number"], select').remove();

            // Add shipping type
            $('<input>').attr({
                type: 'hidden',
                name: 'mandalo_shipping_type',
                value: this.currentType
            }).appendTo($form);

            // Add origin if custom
            if (this.originAddress) {
                $('<input>').attr({
                    type: 'hidden',
                    name: 'mandalo_origin_address',
                    value: this.originAddress
                }).appendTo($form);
            }

            // Add calculated quote data
            if (quoteData) {
                $('<input>').attr({
                    type: 'hidden',
                    name: 'mandalo_calculated_distance',
                    value: quoteData.total_distance
                }).appendTo($form);

                $('<input>').attr({
                    type: 'hidden',
                    name: 'mandalo_calculated_price',
                    value: quoteData.price
                }).appendTo($form);
            }
        },

        /**
         * Check express availability
         */
        checkExpressAvailability: function() {
            var hours = MandaloShipping.express_hours || '08:00-20:00';
            var parts = hours.split('-');
            var start = parts[0];
            var end = parts[1];

            var now = new Date();
            var currentTime = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');

            // Must be within hours and 1 hour before end
            var endHour = parseInt(end.split(':')[0]) - 1;
            var cutoff = endHour.toString().padStart(2, '0') + ':' + end.split(':')[1];

            if (currentTime >= start && currentTime <= cutoff) {
                $('#mandalo-express-unavailable').hide();
                $('.mandalo-express-info .express-text strong').text(MandaloShipping.i18n.express_available);
            } else {
                $('#mandalo-express-unavailable').show();
                $('.mandalo-express-info .express-text strong').text(MandaloShipping.i18n.express_unavailable);
            }
        },

        /**
         * Load time slots for scheduled shipping
         */
        loadTimeSlots: function(date) {
            if (!date) return;

            var $select = $('#mandalo_scheduled_time');
            $select.prop('disabled', true).html('<option>' + MandaloShipping.i18n.loading + '</option>');

            $.ajax({
                url: MandaloShipping.ajax_url,
                type: 'POST',
                data: {
                    action: 'mandalo_get_time_slots',
                    nonce: MandaloShipping.nonce,
                    date: date
                },
                success: function(response) {
                    $select.prop('disabled', false).empty();
                    $select.append('<option value="">' + MandaloShipping.i18n.select_time + '</option>');

                    if (response.success && response.data.slots && response.data.slots.length) {
                        response.data.slots.forEach(function(slot) {
                            $select.append('<option value="' + slot.value + '">' + slot.label + ' (' + slot.remaining + ' ' + MandaloShipping.i18n.available + ')</option>');
                        });
                    } else {
                        $select.append('<option value="">' + MandaloShipping.i18n.no_slots + '</option>');
                    }
                },
                error: function() {
                    $select.prop('disabled', false).html('<option value="">' + MandaloShipping.i18n.error_loading + '</option>');
                }
            });
        },

        /**
         * Validate vehicle requirements
         */
        validateVehicle: function() {
            var weight = parseFloat($('#mandalo_package_weight').val()) || 0;
            var length = parseFloat($('#mandalo_package_length').val()) || 0;
            var width = parseFloat($('#mandalo_package_width').val()) || 0;
            var height = parseFloat($('#mandalo_package_height').val()) || 0;

            if (weight <= 0 || length <= 0 || width <= 0 || height <= 0) {
                $('#mandalo-vehicle-recommendation').hide();
                return;
            }

            $('#mandalo-vehicle-recommendation').addClass('mandalo-loading').show();

            $.ajax({
                url: MandaloShipping.ajax_url,
                type: 'POST',
                data: {
                    action: 'mandalo_validate_vehicle',
                    nonce: MandaloShipping.nonce,
                    weight: weight,
                    length: length,
                    width: width,
                    height: height
                },
                success: function(response) {
                    $('#mandalo-vehicle-recommendation').removeClass('mandalo-loading');

                    if (response.success && response.data.fits) {
                        var vehicle = response.data.vehicle;
                        $('#mandalo-vehicle-details').html(
                            '<div class="mandalo-vehicle-stat">' +
                                '<span class="mandalo-vehicle-stat-label">' + MandaloShipping.i18n.vehicle_type + '</span>' +
                                '<span class="mandalo-vehicle-stat-value">' + vehicle.name + '</span>' +
                            '</div>' +
                            '<div class="mandalo-vehicle-stat">' +
                                '<span class="mandalo-vehicle-stat-label">' + MandaloShipping.i18n.base_cost + '</span>' +
                                '<span class="mandalo-vehicle-stat-value">$' + vehicle.base_rate + '</span>' +
                            '</div>' +
                            '<div class="mandalo-vehicle-stat">' +
                                '<span class="mandalo-vehicle-stat-label">' + MandaloShipping.i18n.per_km + '</span>' +
                                '<span class="mandalo-vehicle-stat-value">$' + vehicle.per_km_rate + '/km</span>' +
                            '</div>'
                        );
                        $('#mandalo-vehicle-recommendation').show();
                        $('#mandalo-vehicle-oversized').hide();
                    } else {
                        $('#mandalo-vehicle-recommendation').hide();
                        $('#mandalo-vehicle-oversized').show();
                    }

                    // Recalculate quote with vehicle info
                    MandaloCheckout.calculateRoute();
                },
                error: function() {
                    $('#mandalo-vehicle-recommendation').removeClass('mandalo-loading').hide();
                }
            });
        },

        /**
         * Checkout updated callback
         */
        onCheckoutUpdated: function() {
            // Re-check current shipping method
            var $checked = $('input[name="shipping_method[0]"]:checked');
            if ($checked.length && $checked.val().indexOf('mandalo_shipping') !== -1) {
                // Ensure our UI is visible
                $('#mandalo-shipping-container').show();
            }
        }
    };

    // Initialize when ready
    MandaloCheckout.init();

    // Export for external access
    window.MandaloCheckout = MandaloCheckout;

})(jQuery);
