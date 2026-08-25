# Contribuir

Gracias por querer aportar. Escribe en **español o inglés**, lo que te resulte
natural — ambos idiomas son bienvenidos en issues y pull requests.

## Poner en marcha

```bash
composer install
vendor/bin/phpunit
```

Requiere PHP **8.1 o superior**.

El paquete sigue **PSR-4** bajo el namespace `CoatiPay\`: el nombre del archivo
debe coincidir con el de la clase. Si renombras una clase, renombra su archivo.

## Antes de abrir el PR

```bash
vendor/bin/phpunit
```

Si arreglas un fallo, añade el test que lo reproduce. Si cambias la API pública,
dilo en la descripción del PR: hay integraciones en producción que dependen de
ella.

## Versiones

La versión **no se fija en `composer.json`**: Packagist la deriva de los tags de
git. Para publicar una versión nueva se crea el tag correspondiente.

## Seguridad

¿Encontraste una vulnerabilidad? **No abras un issue.** Escribe a
**security@coatipay.com** — ver [SECURITY.md](SECURITY.md).
