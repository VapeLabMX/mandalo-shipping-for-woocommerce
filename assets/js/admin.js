/**
 * Mandalo Shipping - Admin JavaScript
 */
(function($) {
    'use strict';

    var MandaloAdmin = {
        instanceId: typeof mandaloInstanceId !== 'undefined' ? mandaloInstanceId : 0,

        init: function() {
            this.loadRules();
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;

            // Add rule
            $('#mandalo-add-rule').on('click', function(e) {
                e.preventDefault();
                self.addRule();
            });

            // Save rules
            $('#mandalo-save-rules').on('click', function(e) {
                e.preventDefault();
                self.saveRules();
            });

            // Remove rule
            $(document).on('click', '.remove-rule', function(e) {
                e.preventDefault();
                $(this).closest('tr').remove();
            });

            // Make rules sortable
            $('#mandalo-rules-body').sortable({
                handle: '.sort-handle',
                placeholder: 'ui-sortable-placeholder',
                update: function() {
                    // Update priority on sort
                }
            });
        },

        loadRules: function() {
            var self = this;

            if (!this.instanceId) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'mandalo_load_rules',
                    instance_id: this.instanceId,
                    nonce: MandaloAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.length) {
                        response.data.forEach(function(rule) {
                            self.addRule(rule);
                        });
                    }
                }
            });
        },

        addRule: function(data) {
            data = data || {};

            var conditions = data.conditions || [{}];
            var firstCond = conditions[0] || {};

            var row = '<tr class="mandalo-rule-row">' +
                '<td><span class="sort-handle dashicons dashicons-menu"></span></td>' +
                '<td>' +
                    '<select name="rule_field[]">' +
                        '<option value="distance_km"' + (firstCond.field === 'distance_km' ? ' selected' : '') + '>Distancia (km)</option>' +
                        '<option value="order_total"' + (firstCond.field === 'order_total' ? ' selected' : '') + '>Subtotal</option>' +
                        '<option value="shipping_type"' + (firstCond.field === 'shipping_type' ? ' selected' : '') + '>Tipo de envío</option>' +
                        '<option value="stop_count"' + (firstCond.field === 'stop_count' ? ' selected' : '') + '>Número de paradas</option>' +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<select name="rule_operator[]">' +
                        '<option value="is"' + (firstCond.operator === 'is' ? ' selected' : '') + '>Es igual a</option>' +
                        '<option value="is not"' + (firstCond.operator === 'is not' ? ' selected' : '') + '>No es igual a</option>' +
                        '<option value="greater than"' + (firstCond.operator === 'greater than' ? ' selected' : '') + '>Mayor que</option>' +
                        '<option value="less than"' + (firstCond.operator === 'less than' ? ' selected' : '') + '>Menor que</option>' +
                        '<option value="between"' + (firstCond.operator === 'between' ? ' selected' : '') + '>Entre</option>' +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<input type="text" name="rule_value[]" value="' + (firstCond.value || '') + '" placeholder="Valor">' +
                '</td>' +
                '<td>' +
                    '<select name="rule_action[]">' +
                        '<option value="flat"' + (data.rate_type === 'flat' ? ' selected' : '') + '>Tarifa fija</option>' +
                        '<option value="per_km"' + (data.rate_type === 'per_km' ? ' selected' : '') + '>Por kilómetro</option>' +
                        '<option value="discount"' + (data.rate_type === 'discount' ? ' selected' : '') + '>Descuento</option>' +
                        '<option value="extra_fee"' + (data.rate_type === 'extra_fee' ? ' selected' : '') + '>Cargo extra</option>' +
                        '<option value="free"' + (data.rate_type === 'free' ? ' selected' : '') + '>Envío gratis</option>' +
                        '<option value="abort"' + (data.rate_type === 'abort' ? ' selected' : '') + '>No disponible</option>' +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<input type="number" name="rule_amount[]" value="' + (data.rate_value || '0') + '" step="0.01">' +
                '</td>' +
                '<td>' +
                    '<a href="#" class="remove-rule" title="Eliminar">' +
                        '<span class="dashicons dashicons-trash"></span>' +
                    '</a>' +
                '</td>' +
                '</tr>';

            $('#mandalo-rules-body').append(row);
        },

        saveRules: function() {
            var self = this;
            var rules = [];

            $('#mandalo-rules-body tr').each(function() {
                var $row = $(this);
                var rule = {
                    conditions: [{
                        field: $row.find('select[name="rule_field[]"]').val(),
                        operator: $row.find('select[name="rule_operator[]"]').val(),
                        value: $row.find('input[name="rule_value[]"]').val()
                    }],
                    rate_type: $row.find('select[name="rule_action[]"]').val(),
                    rate_value: parseFloat($row.find('input[name="rule_amount[]"]').val()) || 0
                };
                rules.push(rule);
            });

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mandalo_save_rules',
                    instance_id: self.instanceId,
                    rules: JSON.stringify(rules),
                    nonce: MandaloAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Reglas guardadas correctamente');
                    } else {
                        alert('Error al guardar: ' + (response.data.message || 'Error desconocido'));
                    }
                },
                error: function() {
                    alert('Error de conexión');
                }
            });
        }
    };

    $(document).ready(function() {
        if ($('#mandalo-rules-table').length) {
            MandaloAdmin.init();
        }
    });

})(jQuery);
