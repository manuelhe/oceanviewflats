# Guests guide

Create a new page that serves to welcome the new guests with instructions and details of the property. We need to share how to get their route to the building, the requirements of the building's management (a link to the guests registry page and information about the building administration fee for the use of common areas), information about the first entry into the property (how to get into for the first time and how to use the entry card), wifi info, information of the guests checkout, and our lines of assistence.

## Reference copy texts

### Intro

> Estamos felices de recibirte en nuestra propiedad, {main-guest-name}. Como parte de los requisitos de ingreso del edificio, debes hacer un registro de todos los huéspedes que se alojarán durante la estadía. Por favor diligencia el siguiente formulario para notificar a la recepción de tu llegada:
>
> https://oceanviewflats.com/registry/es.html?property=1606{property-number}&check_in={checkin-date}&check_out={checkout-date}

### General information

> *🔑 Instrucciones de Acceso y Uso de Energía*
>
> ¡Bienvenido! Queremos que tu estancia sea lo más cómoda posible. Por favor, sigue estas indicaciones para el manejo del ingreso y la electricidad:
>
> *1. Primer Ingreso (Check-in*)
> * *Acceso Inicial*: Utiliza la clave temporal que te hemos proporcionado para abrir la puerta por primera vez.
> * *Tarjeta de Ingreso*: Al entrar, encontrarás la tarjeta de acceso sobre el mesón de la cocina (o en el comedor).
> 
> *2. Durante tu Estancia*
> * *Uso de la Tarjeta*: A partir de tu primer ingreso, deberás usar exclusivamente la tarjeta para entrar y salir del apartamento.
> * *¡No la olvides*!: Es indispensable que lleves la tarjeta contigo cada vez que salgas de la propiedad.
> ** Control de Energía*: Al igual que en un hotel, el apartamento cuenta con un sistema de ahorro de energía. Debes insertar la tarjeta en la ranura ubicada junto a la entrada para habilitar la iluminación y el aire acondicionado.

> *3. Salida (Check-out)*
> * *Devolución*: Al finalizar tu estancia, por favor deja la tarjeta exactamente en el mismo lugar donde la encontraste (mesón de la cocina o comedor).
> * *Cierre*: Asegúrate de que la puerta quede bien cerrada al salir.

> *⚠️ Asistencia Importante*
> Si presentas cualquier inconveniente con la tarjeta o no logras ingresar al apartamento, por favor comunícate con nosotros de inmediato. Podemos generar una nueva clave de acceso de forma remota para ayudarte. 

### WiFi details

Red: *APTO{property-number}*
Contraseña: *Invitado@{property-number}@HN*

### Address

> Dirección: Calle 26 # 2-80. Sector Playa Salguero. Edificio Salguero Sunset.
> Apartamento: *{property-number}*
> Clave temporal de acceso: *{temporal-door-password}#*

## Requirements

1. All dynamic fields should be set using URL query params.
2. Create version of this page in all supported languages.
3. Add a fast way to copy shared data, like the WiFi password and the temporal door password.

