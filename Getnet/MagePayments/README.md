##  Copiar modulo
1.  De la carpeta root de Magento, entrar a la ruta app/code
2.  Dentro de ahi descomprimir el ZIP proporcionado llamado Getnet_MagePayments 1.0.0

##  Como instalar el Modulo:

1.  Entra a la ruta inicial de tu instalacion de Magento 2 (root folder)
2.  Ya dentro por favor correr los siguientes comandos uno por uno.
	php bin/magento cache:disable
	php bin/magento setup:upgrade
	
	php bin/magento setup:di:compile
	php bin/magento cache:enable


##  Configurar el modulo de Pago
1.  Ve a la opcion Stores/Configuration/Sales/Payment Methods
	Ya debera aparecerte como opcion de pago Getnet.
2.  Ingresa las credenciales que fueron otorgadas por tu asesor de Centro de Pagos, la moneda a colocar
	puede ser pesos o dolares, por favor colocar el codigo USD o MXN.

Dudas de la instalacion por favor comunicate con tu asesor o bien al correo integraciones@mitec.com.mx

Compatible hasta Magento 2.3.7-p3