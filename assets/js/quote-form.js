/**
 * Mandalo Quote Form JavaScript
 * Handles quote calculation and cart integration
 */

(function($) {
    'use strict';

    var MandaloQuoteForm = {
        // Store quote data for cart
        quoteData: null,
        // Store map instance
        map: null,
        markers: [],
        // Store geocoded coordinates
        coordinates: {},

        init: function() {
            this.bindEvents();
            this.setupServiceTypeToggle();
        },

        // Called by the PHP-injected MandaloGMapsReady callback when Google API loads
        // after DOM is already ready — flushes any deferred map operations.
        _pendingMapInit: null,

        bindEvents: function() {
            var self = this;

            // Service type selection
            $(document).on('change', 'input[name="service_type"]', function() {
                self.handleServiceTypeChange($(this).val());
            });

            // Form submission
            $('#mandalo-quote-form').on('submit', function(e) {
                e.preventDefault();
                self.calculateQuote();
            });

            // Add destination
            $('#mandalo-add-destination').on('click', function() {
                self.addDestination();
            });

            // Remove destination
            $(document).on('click', '.mandalo-remove-destination', function() {
                self.removeDestination($(this));
            });

            // Add recipient
            $('#mandalo-add-recipient').on('click', function() {
                self.addRecipient();
            });

            // Remove recipient
            $(document).on('click', '.mandalo-remove-recipient', function() {
                self.removeRecipient($(this));
            });

            // Hire button
            $('#mandalo-hire-btn').on('click', function() {
                self.addToCart();
            });

            // Recalculate button
            $('#mandalo-recalculate-btn').on('click', function() {
                self.showForm();
            });

            // Address autocomplete (simple debounced version)
            $(document).on('input', '#origin_address, .mandalo-destination-input', function() {
                self.handleAddressInput($(this));
            });

            // Click outside to close suggestions
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.mandalo-address-input').length) {
                    $('.mandalo-address-suggestions').removeClass('show');
                }
            });
        },

        setupServiceTypeToggle: function() {
            // Set initial state
            var initialType = $('input[name="service_type"]:checked').val();
            this.handleServiceTypeChange(initialType);
        },

        handleServiceTypeChange: function(type) {
            // Update visual selection
            $('.mandalo-service-type-option').removeClass('selected');
            $('input[name="service_type"][value="' + type + '"]').closest('.mandalo-service-type-option').addClass('selected');

            // Show/hide relevant sections
            $('.mandalo-scheduled-options').toggle(type === 'scheduled');
            $('.mandalo-package-options').toggle(type === 'truck');

            // Hide result when type changes
            $('#mandalo-quote-result').slideUp(200);
        },

        addDestination: function() {
            var container = $('#mandalo-destinations-container');
            var currentCount = container.find('.mandalo-destination-row').length;
            var newIndex = currentCount;

            var newRow = $('<div class="mandalo-destination-row" data-index="' + newIndex + '">' +
                '<div class="mandalo-address-input">' +
                    '<span class="mandalo-input-icon">📍</span>' +
                    '<input type="text" name="destinations[]" class="mandalo-input mandalo-input-with-icon mandalo-destination-input" ' +
                           'placeholder="Calle, colonia, CP" autocomplete="off">' +
                    '<input type="text" name="destination_numbers[]" class="mandalo-input mandalo-number-input mandalo-destination-number" ' +
                           'placeholder="No. Ext" maxlength="10">' +
                    '<button type="button" class="mandalo-map-btn" data-field="destination" data-index="' + newIndex + '" title="Ver en mapa">' +
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                            '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>' +
                            '<circle cx="12" cy="10" r="3"></circle>' +
                        '</svg>' +
                    '</button>' +
                    '<div class="mandalo-address-suggestions"></div>' +
                '</div>' +
                '<button type="button" class="mandalo-remove-destination"><span>&times;</span></button>' +
            '</div>');

            container.append(newRow);
            newRow.find('input').first().focus();

            // Show remove buttons
            container.find('.mandalo-remove-destination').show();

            // Hide result
            $('#mandalo-quote-result').slideUp(200);
        },

        removeDestination: function($btn) {
            var row = $btn.closest('.mandalo-destination-row');
            var container = $('#mandalo-destinations-container');

            row.fadeOut(200, function() {
                $(this).remove();

                // Hide remove button if only one destination
                if (container.find('.mandalo-destination-row').length === 1) {
                    container.find('.mandalo-remove-destination').hide();
                }

                // Re-index
                container.find('.mandalo-destination-row').each(function(i) {
                    $(this).attr('data-index', i);
                });
            });

            // Hide result
            $('#mandalo-quote-result').slideUp(200);
        },

        addRecipient: function() {
            var container = $('#mandalo-recipients-container');
            var currentCount = container.find('.mandalo-recipient-row').length;
            var newIndex = currentCount;

            var newRow = $('<div class="mandalo-recipient-row" data-index="' + newIndex + '">' +
                '<div class="mandalo-contact-row">' +
                    '<input type="text" name="recipient_names[]" class="mandalo-input mandalo-recipient-name" ' +
                           'placeholder="Nombre destinatario ' + (newIndex + 1) + '">' +
                    '<input type="tel" name="recipient_phones[]" class="mandalo-input mandalo-recipient-phone" ' +
                           'placeholder="Telefono">' +
                '</div>' +
                '<button type="button" class="mandalo-remove-recipient"><span>&times;</span></button>' +
            '</div>');

            container.append(newRow);
            newRow.find('input').first().focus();

            // Show remove buttons
            container.find('.mandalo-remove-recipient').show();
        },

        removeRecipient: function($btn) {
            var row = $btn.closest('.mandalo-recipient-row');
            var container = $('#mandalo-recipients-container');

            row.fadeOut(200, function() {
                $(this).remove();

                // Hide remove button if only one recipient
                if (container.find('.mandalo-recipient-row').length === 1) {
                    container.find('.mandalo-remove-recipient').hide();
                }

                // Re-index
                container.find('.mandalo-recipient-row').each(function(i) {
                    $(this).attr('data-index', i);
                });
            });
        },

        // ── Map helpers ──────────────────────────────────────────────────────────

        _isGoogle: function() {
            return MandaloQuote.maps_provider === 'google';
        },

        initMap: function() {
            var self = this;

            if (self._isGoogle()) {
                // Destroy previous instance if any
                if (self.map) {
                    self.markers.forEach(function(m) { m.setMap(null); });
                    self.markers = [];
                    // No formal destroy() in Google Maps JS API; just drop reference
                    self.map = null;
                }
                self.map = new google.maps.Map(document.getElementById('mandalo-map-container'), {
                    center: {lat: 19.4326, lng: -99.1332},
                    zoom: 12,
                    // mapId requerido por AdvancedMarkerElement; sin el, el mapa
                    // entra en modo degradado ("This page can't load Google Maps correctly")
                    mapId: (window.MandaloQuote && MandaloQuote.maps_map_id) || 'DEMO_MAP_ID',
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: false
                });
                self._directionsRenderer = null; // reset route renderer
            } else {
                // Leaflet fallback
                if (self.map) {
                    self.map.remove();
                }
                self.map = L.map('mandalo-map-container').setView([19.4326, -99.1332], 12);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap'
                }).addTo(self.map);
            }

            self.markers = [];
        },

        updateMap: function(originCoords, destinationCoords) {
            var self = this;

            if (self._isGoogle()) {
                self._updateMapGoogle(originCoords, destinationCoords);
            } else {
                self._updateMapLeaflet(originCoords, destinationCoords);
            }
        },

        _updateMapLeaflet: function(originCoords, destinationCoords) {
            var self = this;

            $('.mandalo-map-section').stop(true, true).slideDown(300, function() {
                if (!self.map) { self.initMap(); }
                self.map.invalidateSize(true);
            });

            if (!self.map) { self.initMap(); }

            self.markers.forEach(function(m) { self.map.removeLayer(m); });
            self.markers = [];

            var bounds = [];

            if (originCoords && originCoords.lat) {
                var originIcon = L.divIcon({
                    className: 'mandalo-marker-origin',
                    html: '<div style="background:#22c55e;width:24px;height:24px;border-radius:50%;border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);"></div>',
                    iconSize: [24, 24],
                    iconAnchor: [12, 12]
                });
                var originMarker = L.marker([originCoords.lat, originCoords.lon], {icon: originIcon})
                    .addTo(self.map)
                    .bindPopup('<b>Recoger aqui</b>');
                self.markers.push(originMarker);
                bounds.push([originCoords.lat, originCoords.lon]);
            }

            if (destinationCoords && destinationCoords.length > 0) {
                destinationCoords.forEach(function(coords, i) {
                    if (coords && coords.lat) {
                        var destIcon = L.divIcon({
                            className: 'mandalo-marker-dest',
                            html: '<div style="background:#ef4444;width:24px;height:24px;border-radius:50%;border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:12px;">' + (i+1) + '</div>',
                            iconSize: [24, 24],
                            iconAnchor: [12, 12]
                        });
                        var destMarker = L.marker([coords.lat, coords.lon], {icon: destIcon})
                            .addTo(self.map)
                            .bindPopup('<b>Entregar ' + (destinationCoords.length > 1 ? '(Parada ' + (i+1) + ')' : 'aqui') + '</b>');
                        self.markers.push(destMarker);
                        bounds.push([coords.lat, coords.lon]);
                    }
                });
            }

            if (bounds.length > 0) {
                setTimeout(function() {
                    self.map.invalidateSize();
                    if (bounds.length > 1) {
                        self.map.fitBounds(bounds, {padding: [30, 30]});
                    } else {
                        self.map.setView(bounds[0], 15);
                    }
                }, 350);
            }
        },

        _updateMapGoogle: function(originCoords, destinationCoords) {
            var self = this;

            // Show section first, then initialize/refresh Google Map
            $('.mandalo-map-section').stop(true, true).slideDown(300, function() {
                if (!self.map) {
                    self.initMap();
                }
                // Trigger resize so Google Map fills the newly-visible container
                google.maps.event.trigger(self.map, 'resize');
            });

            if (!self.map) { self.initMap(); }

            // Clear previous markers
            self.markers.forEach(function(m) { m.setMap(null); });
            self.markers = [];

            // Clear previous directions renderer
            if (self._directionsRenderer) {
                self._directionsRenderer.setMap(null);
                self._directionsRenderer = null;
            }

            var bounds = new google.maps.LatLngBounds();
            var allPoints = [];

            // Origin marker — green
            if (originCoords && originCoords.lat) {
                var originLatLng = {lat: originCoords.lat, lng: originCoords.lon};
                var originEl = document.createElement('div');
                originEl.className = 'mandalo-gm-label origin';
                originEl.title = 'Recoger aqui';

                var originMarker = new google.maps.marker.AdvancedMarkerElement({
                    position: originLatLng,
                    map: self.map,
                    content: originEl,
                    title: 'Recoger aqui'
                });
                self.markers.push(originMarker);
                bounds.extend(originLatLng);
                allPoints.push(originLatLng);
            }

            // Destination markers — red numbered
            if (destinationCoords && destinationCoords.length > 0) {
                destinationCoords.forEach(function(coords, i) {
                    if (coords && coords.lat) {
                        var destLatLng = {lat: coords.lat, lng: coords.lon};
                        var destEl = document.createElement('div');
                        destEl.className = 'mandalo-gm-label';
                        destEl.textContent = String(i + 1);
                        destEl.title = destinationCoords.length > 1 ? 'Parada ' + (i + 1) : 'Entregar aqui';

                        var destMarker = new google.maps.marker.AdvancedMarkerElement({
                            position: destLatLng,
                            map: self.map,
                            content: destEl,
                            title: destEl.title
                        });
                        self.markers.push(destMarker);
                        bounds.extend(destLatLng);
                        allPoints.push(destLatLng);
                    }
                });
            }

            // Fit map to all points
            if (allPoints.length > 1) {
                self.map.fitBounds(bounds, 40);
            } else if (allPoints.length === 1) {
                self.map.setCenter(allPoints[0]);
                self.map.setZoom(15);
            }

            // Draw route by road with DirectionsService (origin → waypoints → last dest)
            if (originCoords && originCoords.lat && destinationCoords && destinationCoords.length > 0) {
                self._drawGoogleRoute(originCoords, destinationCoords);
            }
        },

        _drawGoogleRoute: function(originCoords, destinationCoords) {
            var self = this;

            var validDests = destinationCoords.filter(function(c) { return c && c.lat; });
            if (validDests.length === 0) { return; }

            var origin = new google.maps.LatLng(originCoords.lat, originCoords.lon);
            var destination = new google.maps.LatLng(
                validDests[validDests.length - 1].lat,
                validDests[validDests.length - 1].lon
            );

            var waypoints = [];
            for (var i = 0; i < validDests.length - 1; i++) {
                waypoints.push({
                    location: new google.maps.LatLng(validDests[i].lat, validDests[i].lon),
                    stopover: true
                });
            }

            var renderer = new google.maps.DirectionsRenderer({
                suppressMarkers: true,          // keep our custom markers
                polylineOptions: {
                    strokeColor: '#F59E0B',      // Mandalo brand amber/yellow
                    strokeWeight: 5,
                    strokeOpacity: 0.85
                }
            });
            renderer.setMap(self.map);
            self._directionsRenderer = renderer;

            var service = new google.maps.DirectionsService();
            service.route({
                origin: origin,
                destination: destination,
                waypoints: waypoints,
                travelMode: google.maps.TravelMode.DRIVING,
                optimizeWaypoints: false
            }, function(result, status) {
                if (status === google.maps.DirectionsStatus.OK) {
                    renderer.setDirections(result);
                } else {
                    // Fallback: draw straight polyline between all points
                    var path = [{lat: originCoords.lat, lng: originCoords.lon}];
                    validDests.forEach(function(c) { path.push({lat: c.lat, lng: c.lon}); });
                    new google.maps.Polyline({
                        path: path,
                        geodesic: true,
                        strokeColor: '#F59E0B',
                        strokeWeight: 4,
                        strokeOpacity: 0.7,
                        map: self.map
                    });
                }
            });
        },

        handleAddressInput: function($input) {
            var self = this;
            var value = $input.val();
            var $suggestions = $input.siblings('.mandalo-address-suggestions');

            // Clear timeout
            if (this.addressTimeout) {
                clearTimeout(this.addressTimeout);
            }

            if (value.length < 3) {
                $suggestions.removeClass('show').empty();
                return;
            }

            // Show loading indicator
            $suggestions.html('<div class="mandalo-suggestion-loading">Buscando direcciones...</div>').addClass('show');

            // Debounce
            this.addressTimeout = setTimeout(function() {
                self.fetchAddressSuggestions(value, $suggestions, $input);
            }, 300);
        },

        fetchAddressSuggestions: function(query, $suggestions, $input) {
            var self = this;

            // Detectar si parece código postal mexicano (5 dígitos)
            var isPostalCode = /^\d{5}$/.test(query.trim());
            var searchQuery = query;
            if (isPostalCode) {
                searchQuery = query + ', Ciudad de México, México';
            }

            // Use Nominatim (OpenStreetMap) for suggestions - free, no API key needed
            // Restricted to Mexico City area for better results
            $.ajax({
                url: 'https://nominatim.openstreetmap.org/search',
                data: {
                    q: searchQuery,
                    format: 'json',
                    addressdetails: 1,
                    limit: 6,
                    countrycodes: 'mx',
                    viewbox: '-99.4,19.6,-98.9,19.1', // CDMX bounding box
                    bounded: isPostalCode ? 0 : 1, // No restringir búsqueda si es CP
                    'accept-language': 'es'
                },
                success: function(data) {
                    $suggestions.empty();

                    if (data && data.length > 0) {
                        data.forEach(function(place) {
                            var addr = place.address || {};
                            // Build a clean address string
                            var parts = [];
                            if (addr.road) {
                                var road = addr.road;
                                if (addr.house_number) road += ' ' + addr.house_number;
                                parts.push(road);
                            } else if (place.display_name) {
                                parts.push(place.display_name.split(',')[0]);
                            }
                            if (addr.suburb || addr.neighbourhood || addr.colony) {
                                parts.push(addr.suburb || addr.neighbourhood || addr.colony);
                            }
                            if (addr.city || addr.town || addr.municipality) {
                                parts.push(addr.city || addr.town || addr.municipality);
                            }

                            var address = parts.length > 0 ? parts.join(', ') : place.display_name;
                            var lat = parseFloat(place.lat);
                            var lon = parseFloat(place.lon);

                            var $item = $('<div class="mandalo-suggestion-item">' + address + '</div>');
                            $item.on('click', function() {
                                $input.val(address);
                                // Store coordinates
                                var inputId = $input.attr('id') || $input.attr('name');
                                self.coordinates[inputId] = {
                                    lat: lat,
                                    lon: lon,
                                    address: address
                                };
                                $input.data('coords', {lat: lat, lon: lon});
                                $suggestions.removeClass('show');
                            });
                            $suggestions.append($item);
                        });
                        $suggestions.addClass('show');
                    } else {
                        // No results - show message
                        $suggestions.html('<div class="mandalo-suggestion-item mandalo-no-results">No encontramos esa direccion. Intenta ser mas especifico.</div>');
                        $suggestions.addClass('show');
                    }
                },
                error: function() {
                    $suggestions.html('<div class="mandalo-suggestion-item mandalo-no-results">Error de conexion. Intenta de nuevo.</div>');
                    $suggestions.addClass('show');
                }
            });
        },

        calculateQuote: function() {
            var self = this;
            var $form = $('#mandalo-quote-form');
            var $btn = $('#mandalo-calculate-btn');

            // Validate
            var origin = $('#origin_address').val().trim();
            var originNumber = $('#origin_number').val().trim();
            var destinations = [];
            var destinationNumbers = [];
            $('.mandalo-destination-input').each(function() {
                var val = $(this).val().trim();
                if (val) destinations.push(val);
            });
            $('.mandalo-destination-number').each(function() {
                destinationNumbers.push($(this).val().trim());
            });

            if (!origin) {
                alert(MandaloQuote.i18n.origin_required);
                $('#origin_address').focus();
                return;
            }

            if (destinations.length === 0) {
                alert(MandaloQuote.i18n.destination_required);
                $('.mandalo-destination-input').first().focus();
                return;
            }

            // Show loading
            $btn.prop('disabled', true).text(MandaloQuote.i18n.calculating);
            $('#mandalo-loading').show();

            // Collect recipients
            var recipientNames = [];
            var recipientPhones = [];
            $('.mandalo-recipient-name').each(function() {
                recipientNames.push($(this).val().trim());
            });
            $('.mandalo-recipient-phone').each(function() {
                recipientPhones.push($(this).val().trim());
            });

            // Collect coordinates if available
            var originCoords = $('#origin_address').data('coords') || null;
            var destCoords = [];
            $('.mandalo-destination-input').each(function() {
                destCoords.push($(this).data('coords') || null);
            });

            // Collect form data
            var formData = {
                action: 'mandalo_quote_calculate',
                nonce: MandaloQuote.nonce,
                origin_address: origin,
                origin_number: originNumber,
                destinations: destinations,
                destination_numbers: destinationNumbers,
                service_type: $('input[name="service_type"]:checked').val(),
                scheduled_date: $('#scheduled_date').val(),
                scheduled_time: $('#scheduled_time').val(),
                sender_name: $('#sender_name').val(),
                sender_phone: $('#sender_phone').val(),
                recipient_names: recipientNames,
                recipient_phones: recipientPhones,
                origin_coords: originCoords,
                dest_coords: destCoords,
                package_weight: $('#package_weight').val(),
                package_length: $('#package_length').val(),
                package_width: $('#package_width').val(),
                package_height: $('#package_height').val()
            };

            $.ajax({
                url: MandaloQuote.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $('#mandalo-loading').hide();
                    $btn.prop('disabled', false).text('Calcular precio');

                    if (response.success) {
                        self.showResult(response.data);
                    } else {
                        alert(response.data.message || MandaloQuote.i18n.error);
                    }
                },
                error: function() {
                    $('#mandalo-loading').hide();
                    $btn.prop('disabled', false).text('Calcular precio');
                    alert(MandaloQuote.i18n.error);
                }
            });
        },

        showResult: function(data) {
            var self = this;
            // Store for cart
            this.quoteData = data;

            // Update result display
            $('#result-distance').text(data.distance_formatted);
            $('#result-time').text(data.duration_formatted);
            $('#result-type').text(data.service_type_label);
            $('#result-total').text(data.price_formatted);

            // Show map with coordinates if available
            if (data.origin_coords || data.dest_coords) {
                self.updateMap(data.origin_coords, data.dest_coords || []);
            }

            // Build breakdown
            var $breakdown = $('#mandalo-result-breakdown').empty();

            if (data.breakdown) {
                $breakdown.append(
                    '<div class="mandalo-breakdown-row">' +
                        '<span>Tarifa base</span>' +
                        '<span>' + MandaloQuote.currency_symbol + data.breakdown.base_fee.toFixed(2) + '</span>' +
                    '</div>'
                );
                $breakdown.append(
                    '<div class="mandalo-breakdown-row">' +
                        '<span>Distancia (' + data.distance_formatted + ' x ' + MandaloQuote.currency_symbol + data.breakdown.per_km_rate + '/km)</span>' +
                        '<span>' + MandaloQuote.currency_symbol + data.breakdown.distance_fee.toFixed(2) + '</span>' +
                    '</div>'
                );

                if (data.breakdown.modifiers && data.breakdown.modifiers.length > 0) {
                    data.breakdown.modifiers.forEach(function(mod) {
                        var className = mod.amount < 0 ? 'discount' : 'surcharge';
                        var sign = mod.amount < 0 ? '' : '+';
                        $breakdown.append(
                            '<div class="mandalo-breakdown-row ' + className + '">' +
                                '<span>' + mod.label + '</span>' +
                                '<span>' + sign + MandaloQuote.currency_symbol + mod.amount.toFixed(2) + '</span>' +
                            '</div>'
                        );
                    });
                }
            }

            // Show result section
            $('#mandalo-quote-result').slideDown(300);

            // Scroll to result
            $('html, body').animate({
                scrollTop: $('#mandalo-quote-result').offset().top - 100
            }, 300);
        },

        showForm: function() {
            $('#mandalo-quote-result').slideUp(200);
            $('html, body').animate({
                scrollTop: $('#mandalo-quote-container').offset().top - 50
            }, 300);
        },

        addToCart: function() {
            var self = this;
            var $btn = $('#mandalo-hire-btn');

            if (!this.quoteData || !this.quoteData.cart_data) {
                alert(MandaloQuote.i18n.error);
                return;
            }

            $btn.prop('disabled', true).text(MandaloQuote.i18n.processing);

            $.ajax({
                url: MandaloQuote.ajax_url,
                type: 'POST',
                data: {
                    action: 'mandalo_add_service_to_cart',
                    nonce: MandaloQuote.nonce,
                    cart_data: this.quoteData.cart_data
                },
                success: function(response) {
                    if (response.success) {
                        // Redirect to checkout
                        window.location.href = response.data.redirect || MandaloQuote.checkout_url;
                    } else {
                        alert(response.data.message || MandaloQuote.i18n.error);
                        $btn.prop('disabled', false).text(MandaloQuote.i18n.add_to_cart);
                    }
                },
                error: function() {
                    alert(MandaloQuote.i18n.error);
                    $btn.prop('disabled', false).text(MandaloQuote.i18n.add_to_cart);
                }
            });
        }
    };

    // Address Book Module
    var MandaloAddressBook = {
        currentType: 'origin', // 'origin' or 'destination'
        addresses: [],
        formCoords: null,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // Open modal
            $(document).on('click', '.mandalo-address-book-btn', function() {
                self.currentType = $(this).data('type') || 'origin';
                self.openModal();
            });

            // Close modal
            $(document).on('click', '.mandalo-modal-close, .mandalo-modal', function(e) {
                if (e.target === this) {
                    self.closeModal();
                }
            });

            // Select address
            $(document).on('click', '.mandalo-address-item .mandalo-address-info', function() {
                var $item = $(this).closest('.mandalo-address-item');
                self.selectAddress($item.data('address'));
            });

            // Edit address
            $(document).on('click', '.mandalo-address-action.edit', function(e) {
                e.stopPropagation();
                var id = $(this).closest('.mandalo-address-item').data('id');
                self.editAddress(id);
            });

            // Delete address
            $(document).on('click', '.mandalo-address-action.delete', function(e) {
                e.stopPropagation();
                var id = $(this).closest('.mandalo-address-item').data('id');
                if (confirm('¿Eliminar esta direccion?')) {
                    self.deleteAddress(id);
                }
            });

            // Set default
            $(document).on('click', '.mandalo-address-action.default', function(e) {
                e.stopPropagation();
                var id = $(this).closest('.mandalo-address-item').data('id');
                self.setDefault(id);
            });

            // Show add form
            $('#mandalo-add-new-address').on('click', function() {
                self.showAddForm();
            });

            // Cancel form
            $('#mandalo-cancel-address').on('click', function() {
                self.hideForm();
            });

            // Save address
            $('#mandalo-save-address').on('click', function() {
                self.saveAddress();
            });

            // Autocomplete in form
            $(document).on('input', '#address_full', function() {
                MandaloQuoteForm.handleAddressInput($(this));
            });

            // Show save button when address is entered
            $(document).on('blur', '#origin_address, .mandalo-destination-input', function() {
                var $btn = $(this).closest('.mandalo-form-section').find('.mandalo-save-address-btn');
                if ($(this).val().length > 5) {
                    $btn.slideDown(200);
                }
            });

            // Save from main form
            $(document).on('click', '.mandalo-save-address-btn', function() {
                var field = $(this).data('field');
                var $input = field === 'origin' ? $('#origin_address') : $(this).closest('.mandalo-form-section').find('.mandalo-destination-input').first();
                self.quickSaveAddress($input.val(), $input.data('coords'));
            });
        },

        openModal: function() {
            $('#mandalo-address-modal').fadeIn(200);
            this.loadAddresses();
        },

        closeModal: function() {
            $('#mandalo-address-modal').fadeOut(200);
            this.hideForm();
        },

        loadAddresses: function() {
            var self = this;
            var $list = $('#mandalo-address-list');
            $list.html('<p class="mandalo-loading-text">Cargando direcciones...</p>');

            $.ajax({
                url: MandaloQuote.ajax_url,
                type: 'POST',
                data: {
                    action: 'mandalo_get_addresses',
                    nonce: MandaloQuote.nonce,
                    type: self.currentType === 'origin' ? 'origin' : 'destination'
                },
                success: function(response) {
                    if (response.success) {
                        self.addresses = response.data.addresses;
                        self.renderAddresses(response.data);
                    } else {
                        $list.html('<p class="mandalo-loading-text">Error al cargar direcciones</p>');
                    }
                },
                error: function() {
                    $list.html('<p class="mandalo-loading-text">Error de conexion</p>');
                }
            });
        },

        renderAddresses: function(data) {
            var self = this;
            var $list = $('#mandalo-address-list');
            var addresses = data.addresses;

            if (!addresses || addresses.length === 0) {
                $list.html(
                    '<div class="mandalo-empty-addresses">' +
                        '<div class="empty-icon">📍</div>' +
                        '<p>No tienes direcciones guardadas</p>' +
                        '<p style="font-size:0.85rem">Agrega direcciones frecuentes para cotizar mas rapido</p>' +
                    '</div>'
                );
                return;
            }

            var html = '';
            addresses.forEach(function(addr) {
                var isDefault = (self.currentType === 'origin' && addr.is_default_origin == 1) ||
                               (self.currentType === 'destination' && addr.is_default_destination == 1);
                var icon = addr.title.toLowerCase().includes('casa') ? '🏠' :
                          addr.title.toLowerCase().includes('oficina') ? '🏢' :
                          addr.title.toLowerCase().includes('bodega') ? '📦' : '📍';

                html += '<div class="mandalo-address-item' + (isDefault ? ' is-default' : '') + '" data-id="' + addr.id + '" data-address="' + self.escapeHtml(JSON.stringify(addr)) + '">';
                html += '  <div class="mandalo-address-icon">' + icon + '</div>';
                html += '  <div class="mandalo-address-info">';
                html += '    <div class="mandalo-address-title">' + self.escapeHtml(addr.title);
                if (isDefault) {
                    html += ' <span class="default-badge">Predeterminado</span>';
                }
                html += '    </div>';
                html += '    <div class="mandalo-address-text">' + self.escapeHtml(addr.address) + '</div>';
                if (addr.contact_name) {
                    html += '    <div class="mandalo-address-contact">' + self.escapeHtml(addr.contact_name);
                    if (addr.contact_phone) html += ' - ' + self.escapeHtml(addr.contact_phone);
                    html += '</div>';
                }
                html += '  </div>';
                html += '  <div class="mandalo-address-actions">';
                if (!isDefault) {
                    html += '    <button class="mandalo-address-action default" title="Hacer predeterminado">⭐</button>';
                }
                html += '    <button class="mandalo-address-action edit" title="Editar">✏️</button>';
                html += '    <button class="mandalo-address-action delete" title="Eliminar">🗑️</button>';
                html += '  </div>';
                html += '</div>';
            });

            $list.html(html);
        },

        selectAddress: function(addressJson) {
            var addr = typeof addressJson === 'string' ? JSON.parse(addressJson) : addressJson;

            if (this.currentType === 'origin') {
                $('#origin_address').val(addr.address);
                if (addr.lat && addr.lng) {
                    $('#origin_address').data('coords', {lat: parseFloat(addr.lat), lon: parseFloat(addr.lng)});
                }
                // Fill sender info if available
                if (addr.contact_name) $('#sender_name').val(addr.contact_name);
                if (addr.contact_phone) $('#sender_phone').val(addr.contact_phone);
            } else {
                // Find first empty destination or the first one
                var $destInput = $('.mandalo-destination-input').filter(function() {
                    return !$(this).val();
                }).first();

                if ($destInput.length === 0) {
                    $destInput = $('.mandalo-destination-input').first();
                }

                $destInput.val(addr.address);
                if (addr.lat && addr.lng) {
                    $destInput.data('coords', {lat: parseFloat(addr.lat), lon: parseFloat(addr.lng)});
                }
                // Fill recipient info if available
                if (addr.contact_name) {
                    var $row = $destInput.closest('.mandalo-destination-row');
                    var index = $row.data('index') || 0;
                    var $recipientRow = $('#mandalo-recipients-container .mandalo-recipient-row').eq(index);
                    if ($recipientRow.length) {
                        $recipientRow.find('.mandalo-recipient-name').val(addr.contact_name);
                        $recipientRow.find('.mandalo-recipient-phone').val(addr.contact_phone || '');
                    }
                }
            }

            this.closeModal();
        },

        showAddForm: function() {
            $('#edit_address_id').val('');
            $('#address_title').val('');
            $('#address_full').val('');
            $('#address_contact_name').val('');
            $('#address_contact_phone').val('');
            $('#address_type').val('both');
            $('#mandalo-form-title').text('Nueva Direccion');
            this.formCoords = null;

            $('.mandalo-modal-body').slideUp(200);
            $('#mandalo-address-form').slideDown(200);
        },

        hideForm: function() {
            $('#mandalo-address-form').slideUp(200);
            $('.mandalo-modal-body').slideDown(200);
        },

        editAddress: function(id) {
            var addr = this.addresses.find(function(a) { return a.id == id; });
            if (!addr) return;

            $('#edit_address_id').val(addr.id);
            $('#address_title').val(addr.title);
            $('#address_full').val(addr.address);
            $('#address_contact_name').val(addr.contact_name || '');
            $('#address_contact_phone').val(addr.contact_phone || '');
            $('#address_type').val(addr.address_type || 'both');
            $('#mandalo-form-title').text('Editar Direccion');
            this.formCoords = addr.lat ? {lat: addr.lat, lng: addr.lng} : null;

            $('.mandalo-modal-body').slideUp(200);
            $('#mandalo-address-form').slideDown(200);
        },

        saveAddress: function() {
            var self = this;
            var title = $('#address_title').val().trim();
            var address = $('#address_full').val().trim();

            if (!title || !address) {
                alert('Titulo y direccion son requeridos');
                return;
            }

            var data = {
                action: 'mandalo_save_address',
                nonce: MandaloQuote.nonce,
                id: $('#edit_address_id').val() || 0,
                title: title,
                address: address,
                contact_name: $('#address_contact_name').val().trim(),
                contact_phone: $('#address_contact_phone').val().trim(),
                address_type: $('#address_type').val(),
                lat: self.formCoords ? self.formCoords.lat : null,
                lng: self.formCoords ? self.formCoords.lng : null
            };

            $.ajax({
                url: MandaloQuote.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        self.hideForm();
                        self.addresses = response.data.addresses;
                        self.renderAddresses(response.data);
                    } else {
                        alert(response.data.message || 'Error al guardar');
                    }
                },
                error: function() {
                    alert('Error de conexion');
                }
            });
        },

        deleteAddress: function(id) {
            var self = this;

            $.ajax({
                url: MandaloQuote.ajax_url,
                type: 'POST',
                data: {
                    action: 'mandalo_delete_address',
                    nonce: MandaloQuote.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        self.addresses = response.data.addresses;
                        self.renderAddresses(response.data);
                    }
                }
            });
        },

        setDefault: function(id) {
            var self = this;
            var defaultType = self.currentType === 'origin' ? 'origin' : 'destination';

            $.ajax({
                url: MandaloQuote.ajax_url,
                type: 'POST',
                data: {
                    action: 'mandalo_set_default_address',
                    nonce: MandaloQuote.nonce,
                    id: id,
                    default_type: defaultType
                },
                success: function(response) {
                    if (response.success) {
                        self.addresses = response.data.addresses;
                        self.renderAddresses(response.data);
                    }
                }
            });
        },

        quickSaveAddress: function(address, coords) {
            if (!address) return;

            var title = prompt('Nombre para esta direccion (ej: Casa, Oficina):', '');
            if (!title) return;

            var data = {
                action: 'mandalo_save_address',
                nonce: MandaloQuote.nonce,
                id: 0,
                title: title,
                address: address,
                contact_name: '',
                contact_phone: '',
                address_type: 'both',
                lat: coords ? coords.lat : null,
                lng: coords ? coords.lon : null
            };

            $.ajax({
                url: MandaloQuote.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        alert('Direccion guardada en tu agenda');
                    }
                }
            });
        },

        escapeHtml: function(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
    };

    // Fullscreen Map Module — supports Leaflet (fallback) and Google Maps
    var MandaloFullscreenMap = {
        map: null,
        marker: null,
        currentField: null,
        currentIndex: null,
        selectedCoords: null,
        selectedAddress: null,

        init: function() {
            this.bindEvents();
        },

        _isGoogle: function() {
            return MandaloQuote.maps_provider === 'google';
        },

        bindEvents: function() {
            var self = this;

            $(document).on('click', '.mandalo-map-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var field = $(this).data('field');
                var index = $(this).data('index') || 0;
                self.openMap(field, index);
            });

            $('#mandalo-map-back').on('click', function() {
                self.closeMap();
            });

            $('#mandalo-confirm-location').on('click', function() {
                self.confirmLocation();
            });

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#mandalo-fullscreen-map-modal').is(':visible')) {
                    self.closeMap();
                }
            });
        },

        openMap: function(field, index) {
            var self = this;
            self.currentField = field;
            self.currentIndex = index;

            var $input = field === 'origin'
                ? $('#origin_address')
                : $('.mandalo-destination-row[data-index="' + index + '"] .mandalo-destination-input');

            var address = $input.val();
            var coords  = $input.data('coords');

            $('#mandalo-map-title').text(field === 'origin' ? 'Ubicacion de recoleccion' : 'Ubicacion de entrega');
            $('#mandalo-fullscreen-map-modal').fadeIn(200);
            $('body').css('overflow', 'hidden');

            setTimeout(function() {
                self.initFullscreenMap(coords, address);
            }, 100);
        },

        closeMap: function() {
            $('#mandalo-fullscreen-map-modal').fadeOut(200);
            $('body').css('overflow', '');

            if (this._isGoogle()) {
                // Google Maps has no destroy(); drop reference, modal hides the container
                this.map = null;
                this.marker = null;
            } else {
                if (this.map) {
                    this.map.remove();
                    this.map = null;
                }
            }
        },

        initFullscreenMap: function(coords, address) {
            var self = this;

            var lat  = coords ? coords.lat : 19.4326;
            var lng  = coords ? (coords.lon || coords.lng) : -99.1332;
            var zoom = coords ? 16 : 12;

            self.selectedCoords  = {lat: lat, lon: lng};
            self.selectedAddress = address || '';

            if (address) {
                $('#mandalo-selected-address .mandalo-address-text').text(address);
            }

            if (self._isGoogle()) {
                self._initFullscreenGoogle(lat, lng, zoom, coords, address);
            } else {
                self._initFullscreenLeaflet(lat, lng, zoom, coords, address);
            }
        },

        // ── Google Maps fullscreen ────────────────────────────────────────────

        _initFullscreenGoogle: function(lat, lng, zoom, coords, address) {
            var self = this;

            self.map = new google.maps.Map(document.getElementById('mandalo-fullscreen-map'), {
                center: {lat: lat, lng: lng},
                zoom: zoom,
                // mapId requerido por AdvancedMarkerElement (ver _updateMapGoogle)
                mapId: (window.MandaloQuote && MandaloQuote.maps_map_id) || 'DEMO_MAP_ID',
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                zoomControl: true,
                zoomControlOptions: { position: google.maps.ControlPosition.RIGHT_TOP }
            });

            // Amber teardrop marker element
            var pickerEl = document.createElement('div');
            pickerEl.innerHTML =
                '<div class="mandalo-gm-picker-pulse"></div>' +
                '<div class="mandalo-gm-picker"></div>';
            pickerEl.style.cssText = 'position:relative;width:40px;height:40px;';

            self.marker = new google.maps.marker.AdvancedMarkerElement({
                position: {lat: lat, lng: lng},
                map: self.map,
                content: pickerEl,
                gmpDraggable: true,
                title: 'Arrastra para ajustar'
            });

            // Drag end → update coords and reverse geocode
            self.marker.addEventListener('dragend', function() {
                var pos = self.marker.position;
                var mlat = typeof pos.lat === 'function' ? pos.lat() : pos.lat;
                var mlng = typeof pos.lng === 'function' ? pos.lng() : pos.lng;
                self.selectedCoords = {lat: mlat, lon: mlng};
                self.reverseGeocode(mlat, mlng);
            });

            // Click on map → move marker and reverse geocode
            self.map.addListener('click', function(e) {
                var clat = e.latLng.lat();
                var clng = e.latLng.lng();
                self.marker.position = {lat: clat, lng: clng};
                self.selectedCoords  = {lat: clat, lon: clng};
                self.reverseGeocode(clat, clng);
            });

            // Auto geolocation if no coords provided
            if (!coords && navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    var glat = position.coords.latitude;
                    var glng = position.coords.longitude;
                    self.map.setCenter({lat: glat, lng: glng});
                    self.map.setZoom(16);
                    self.marker.position = {lat: glat, lng: glng};
                    self.selectedCoords  = {lat: glat, lon: glng};
                    self.reverseGeocode(glat, glng);
                }, function() { /* stay at default */ });
            } else if (coords && !address) {
                self.reverseGeocode(lat, lng);
            }

            self._addGoogleLocationButton();
        },

        _addGoogleLocationButton: function() {
            var self = this;

            var btn = document.createElement('div');
            btn.className = 'mandalo-location-btn';
            btn.innerHTML =
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<circle cx="12" cy="12" r="10"></circle>' +
                '<circle cx="12" cy="12" r="3" fill="currentColor"></circle>' +
                '<line x1="12" y1="2" x2="12" y2="6"></line>' +
                '<line x1="12" y1="18" x2="12" y2="22"></line>' +
                '<line x1="2" y1="12" x2="6" y2="12"></line>' +
                '<line x1="18" y1="12" x2="22" y2="12"></line>' +
                '</svg>';
            btn.title = 'Mi ubicacion';

            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                self.goToCurrentLocation(btn);
            });

            self.map.controls[google.maps.ControlPosition.RIGHT_BOTTOM].push(btn);
        },

        // ── Leaflet fullscreen ────────────────────────────────────────────────

        _initFullscreenLeaflet: function(lat, lng, zoom, coords, address) {
            var self = this;

            self.map = L.map('mandalo-fullscreen-map', { zoomControl: false }).setView([lat, lng], zoom);
            L.control.zoom({ position: 'topright' }).addTo(self.map);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19
            }).addTo(self.map);

            var markerIcon = L.divIcon({
                className: 'mandalo-draggable-marker',
                html: '<div style="position:relative;">' +
                        '<div class="mandalo-marker-pulse"></div>' +
                        '<div style="background:#f59e0b;width:40px;height:40px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:4px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,0.3);"></div>' +
                      '</div>',
                iconSize: [40, 40],
                iconAnchor: [20, 40]
            });

            self.marker = L.marker([lat, lng], { icon: markerIcon, draggable: true }).addTo(self.map);

            self.marker.on('dragend', function(e) {
                var pos = e.target.getLatLng();
                self.selectedCoords = {lat: pos.lat, lon: pos.lng};
                self.reverseGeocode(pos.lat, pos.lng);
            });

            self.map.on('click', function(e) {
                self.marker.setLatLng(e.latlng);
                self.selectedCoords = {lat: e.latlng.lat, lon: e.latlng.lng};
                self.reverseGeocode(e.latlng.lat, e.latlng.lng);
            });

            if (!coords && navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    var pos = [position.coords.latitude, position.coords.longitude];
                    self.map.setView(pos, 16);
                    self.marker.setLatLng(pos);
                    self.selectedCoords = {lat: pos[0], lon: pos[1]};
                    self.reverseGeocode(pos[0], pos[1]);
                }, function() { /* stay at default */ });
            } else if (coords && !address) {
                self.reverseGeocode(lat, lng);
            }

            self.addLocationButton();
        },

        addLocationButton: function() {
            var self = this;
            var locationBtn = L.control({position: 'bottomright'});

            locationBtn.onAdd = function() {
                var div = L.DomUtil.create('div', 'mandalo-location-btn');
                div.innerHTML =
                    '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                    '<circle cx="12" cy="12" r="10"></circle>' +
                    '<circle cx="12" cy="12" r="3" fill="currentColor"></circle>' +
                    '<line x1="12" y1="2" x2="12" y2="6"></line>' +
                    '<line x1="12" y1="18" x2="12" y2="22"></line>' +
                    '<line x1="2" y1="12" x2="6" y2="12"></line>' +
                    '<line x1="18" y1="12" x2="22" y2="12"></line>' +
                    '</svg>';
                div.title = 'Mi ubicacion';

                L.DomEvent.on(div, 'click', function(e) {
                    L.DomEvent.stopPropagation(e);
                    self.goToCurrentLocation(div);
                });

                return div;
            };

            locationBtn.addTo(self.map);
        },

        // ── Shared ────────────────────────────────────────────────────────────

        goToCurrentLocation: function(btn) {
            var self = this;

            if (!navigator.geolocation) {
                alert('Tu navegador no soporta geolocalizacion');
                return;
            }

            $(btn).addClass('locating');

            navigator.geolocation.getCurrentPosition(function(position) {
                var glat = position.coords.latitude;
                var glng = position.coords.longitude;

                if (self._isGoogle() && self.map) {
                    self.map.setCenter({lat: glat, lng: glng});
                    self.map.setZoom(17);
                    if (self.marker) { self.marker.position = {lat: glat, lng: glng}; }
                } else if (self.map) {
                    self.map.setView([glat, glng], 17);
                    if (self.marker) { self.marker.setLatLng([glat, glng]); }
                }

                self.selectedCoords = {lat: glat, lon: glng};
                self.reverseGeocode(glat, glng);
                $(btn).removeClass('locating');
            }, function() {
                $(btn).removeClass('locating');
                alert('No pudimos obtener tu ubicacion. Verifica los permisos de tu navegador.');
            }, {
                enableHighAccuracy: true,
                timeout: 10000
            });
        },

        // Nominatim reverse geocode — used by both providers
        reverseGeocode: function(lat, lng) {
            var self = this;

            $('#mandalo-selected-address .mandalo-address-text').text('Obteniendo direccion...');

            $.ajax({
                url: 'https://nominatim.openstreetmap.org/reverse',
                data: {
                    lat: lat,
                    lon: lng,
                    format: 'json',
                    addressdetails: 1,
                    'accept-language': 'es'
                },
                success: function(data) {
                    if (data && data.display_name) {
                        var addr = data.address || {};
                        var parts = [];

                        if (addr.road) parts.push(addr.road);
                        if (addr.house_number) parts[parts.length - 1] += ' ' + addr.house_number;
                        if (addr.suburb || addr.neighbourhood) parts.push(addr.suburb || addr.neighbourhood);
                        if (addr.city || addr.town || addr.municipality) parts.push(addr.city || addr.town || addr.municipality);
                        if (addr.state) parts.push(addr.state);

                        var formattedAddress = parts.length > 0 ? parts.join(', ') : data.display_name;

                        self.selectedAddress = formattedAddress;
                        $('#mandalo-selected-address .mandalo-address-text').text(formattedAddress);
                    }
                },
                error: function() {
                    self.selectedAddress = 'Lat: ' + lat.toFixed(6) + ', Lng: ' + lng.toFixed(6);
                    $('#mandalo-selected-address .mandalo-address-text').text(self.selectedAddress);
                }
            });
        },

        confirmLocation: function() {
            var self = this;

            if (!self.selectedCoords || !self.selectedAddress) {
                alert('Por favor selecciona una ubicacion en el mapa');
                return;
            }

            var $input = self.currentField === 'origin'
                ? $('#origin_address')
                : $('.mandalo-destination-row[data-index="' + self.currentIndex + '"] .mandalo-destination-input');

            $input.val(self.selectedAddress);
            $input.data('coords', self.selectedCoords);

            MandaloQuoteForm.coordinates[$input.attr('id') || $input.attr('name')] = {
                lat: self.selectedCoords.lat,
                lon: self.selectedCoords.lon,
                address: self.selectedAddress
            };

            self.closeMap();
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        if ($('#mandalo-quote-container').length > 0) {
            MandaloQuoteForm.init();
            MandaloAddressBook.init();
            MandaloFullscreenMap.init();
        }
    });

})(jQuery);
