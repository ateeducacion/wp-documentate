=== Documentate – Generador de resoluciones ===
Contributors: ateeducacion
Tags: documentos, resoluciones, docx, pdf, opentbs
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.0.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generador de resoluciones y documentos administrativos estructurados a partir de plantillas ODT/DOCX, con exportación a DOCX y PDF.

== Description ==

Documentate es un plugin de WordPress desarrollado en el ATE para crear resoluciones y documentos administrativos estructurados a partir de plantillas ODT/DOCX.

Utiliza OpenTBS para combinar los datos del documento con la plantilla y permite convertir a PDF/DOCX mediante Collabora Online (en el servidor) o LibreOffice WASM (en el navegador).

### Características

– **Tipos de documento (plantillas)**: definidos como taxonomía con campos basados en un esquema.
– **Generación de ODT/DOCX** a partir de plantillas mediante OpenTBS.
– **Conversión opcional a PDF** (y entre formatos ofimáticos) con Collabora Online (servidor) o LibreOffice WASM (navegador, experimental).
– **Filtrado por ámbito de usuario** (categorías jerárquicas) para controlar la visibilidad de los documentos.
– **Flujo de trabajo, revisiones, adjuntos y edición colaborativa.**
– **Compatible con multisitio.**

== Installation ==

1. Descarga la última versión desde la página de *releases* de GitHub.
2. Sube el plugin a tu sitio mediante **Plugins > Añadir nuevo > Subir plugin**.
3. Activa el plugin desde el menú 'Plugins'.
4. Configura el motor de conversión y las demás opciones en **Ajustes > Documentate**.

== Frequently Asked Questions ==

= ¿Qué motores de conversión admite? =
Collabora Online (en el servidor, recomendado) y LibreOffice WASM (en el navegador, experimental).

= ¿Cómo se controla quién ve cada documento? =
Mediante un ámbito por usuario (categorías jerárquicas). Los administradores ven todos los documentos; el resto solo los de su ámbito y sus subcategorías.

== Screenshots ==

1. **Editor de resolución**
   Campos meta para las distintas secciones del documento.

2. **Exportación DOCX/PDF**
   Genera documentos a partir de plantillas ODT/DOCX.
