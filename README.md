# Mandalo Shipping for WooCommerce

Plugin de envíos para WooCommerce con múltiples opciones de entrega para CDMX y área metropolitana.

## Características

### 6 Tipos de Envío

1. **Punto A-B (Estándar)** - Envío directo de origen a destino
2. **Multi-destino Optimizado** - Múltiples paradas con ruta optimizada (descuento incluido)
3. **Multi-destino Orden Específico** - Múltiples paradas en orden especificado por el cliente
4. **Express** - Entrega el mismo día (horario limitado, precio premium)
5. **Programado** - Entrega en fecha/hora específica (descuento incluido)
6. **Camioneta** - Para paquetes grandes con cotización por peso/dimensiones

### Funcionalidades Técnicas

- **Cálculo de Distancia**: OSRM (servidor propio) + Nominatim/Photon para geocoding
- **Optimización de Rutas**: Google Directions API compatible (via OSRM trip service)
- **Sistema de Reglas**: Condiciones personalizables para modificar precios
- **Integración con Admin Mandalo**: Envía órdenes al sistema de gestión de rutas
- **HPOS Compatible**: Funciona con el nuevo sistema de órdenes de WooCommerce

## Instalación

1. Subir el directorio `mandalo-shipping-for-woocommerce` a `/wp-content/plugins/`
2. Activar el plugin desde el panel de WordPress
3. Ir a WooCommerce > Configuración > Envío
4. Agregar una zona de envío y seleccionar "Mandalo Shipping"
5. Configurar las opciones del método

## Configuración

### Opciones del Método de Envío

| Opción | Descripción | Default |
|--------|-------------|---------|
| Tarifa base | Costo fijo inicial | $45 MXN |
| Tarifa por km | Costo por kilómetro | $8 MXN |
| Multiplicador Express | Factor para envíos express | 1.5x |
| Descuento Programado | Descuento para envíos programados | 10% |
| Descuento Multi-parada | Descuento base por optimizar ruta | 15% |
| Distancia máxima | Límite de cobertura | 50 km |
| Horario Express | Ventana de disponibilidad | 08:00-20:00 |

### Opciones Globales (wp_options)

```php
mandalo_osrm_endpoint     // URL del servidor OSRM
mandalo_api_endpoint      // URL del API de admin.mandalo.mx
mandalo_distance_multiplier // Factor de inflación de distancia
```

## Estructura de Base de Datos

### wp_mandalo_shipping_rules

Reglas personalizadas de precios.

```sql
- id
- instance_id
- shipping_type
- name
- conditions (JSON)
- rate_type (flat, per_km, discount, extra_fee, free, abort)
- rate_value
- priority
- enabled
```

### wp_mandalo_time_slots

Horarios disponibles para envíos programados.

```sql
- id
- day_of_week (0-6)
- start_time
- end_time
- max_orders
- enabled
```

### wp_mandalo_vehicles

Tipos de vehículo disponibles.

```sql
- id
- name
- type (moto, auto, camioneta_chica, camioneta_grande)
- max_weight, max_length, max_width, max_height
- base_rate, per_km_rate
- enabled
```

## API AJAX Endpoints

### Frontend (Checkout)

| Action | Descripción |
|--------|-------------|
| `mandalo_calculate_route` | Calcula ruta multi-parada |
| `mandalo_get_time_slots` | Obtiene horarios disponibles |
| `mandalo_validate_vehicle` | Valida dimensiones de paquete |

### Admin

| Action | Descripción |
|--------|-------------|
| `mandalo_save_rules` | Guarda reglas de precios |
| `mandalo_load_rules` | Carga reglas existentes |

## Order Meta Data

El plugin guarda la siguiente información en cada orden:

```php
_mandalo_shipping_type    // Tipo de envío seleccionado
_mandalo_stops            // Array de direcciones adicionales
_mandalo_optimize_route   // Si la ruta fue optimizada
_mandalo_scheduled_date   // Fecha programada
_mandalo_scheduled_time   // Horario programado
_mandalo_package_weight   // Peso del paquete (camioneta)
_mandalo_package_dimensions // Dimensiones del paquete
```

## Integración con Admin Mandalo

El plugin está diseñado para integrarse con el sistema de administración de rutas `vaperoute-admin` (ahora Mandalo):

1. Las órdenes se crean en WooCommerce con toda la información de envío
2. El sistema admin (https://admin.mandalo.mx) obtiene las órdenes vía WC REST API
3. Las rutas se optimizan y asignan a conductores
4. El tracking se actualiza en tiempo real

## Dependencias

- WooCommerce 6.0+
- PHP 7.4+
- WordPress 5.8+
- OSRM Server (https://osrm.vapelab.mx)

## Desarrollo

### Basado en

- `distance-rate-shipping` - Plugin de VapeLab para cálculo de distancia
- `vaperoute-admin` - Sistema de administración de rutas Angular

### Estructura del Plugin

```
mandalo-shipping-for-woocommerce/
├── mandalo-shipping.php          # Main plugin file
├── includes/
│   ├── class-mandalo-installer.php
│   ├── class-mandalo-distance-calculator.php
│   ├── class-mandalo-pricing-engine.php
│   ├── class-mandalo-multi-address.php
│   ├── class-mandalo-scheduling.php
│   ├── class-mandalo-vehicle-handler.php
│   └── class-wc-shipping-mandalo.php
├── assets/
│   ├── css/
│   │   ├── checkout.css
│   │   └── admin.css
│   └── js/
│       ├── checkout.js
│       └── admin.js
└── languages/
```

## Changelog

### 1.0.0
- Release inicial
- 6 tipos de envío implementados
- Cálculo de distancia OSRM
- Optimización de rutas multi-parada
- Sistema de scheduling
- Validación de vehículos

## License

GPL v2 or later

## Author

Mandalo / VapeLab - https://mandalo.mx
