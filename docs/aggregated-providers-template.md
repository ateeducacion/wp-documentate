# Aggregated providers with nested conceptos (propuesta de gasto)

`fixtures/propuestagasto_agregados.odt` is the forward-looking variant of the
propuesta de gasto template where **servicios, suministros and expertos are
repeatable**: a document can carry several providers of each kind, and each
provider carries its own table of conceptos. The classic
`fixtures/propuestagasto.odt` (one provider per kind) stays untouched — demo
data and tests keep using it.

## How the template is structured

Each kind is an **explicit TinyButStrong block** wrapped around its table, so
everything between the markers — provider data, conceptos rows **and the
totals** — visibly belongs to one provider:

```text
[servicios;block=begin;sub1=conceptos]
  ┌──────────────────────────────────────────────────────┐
  │ [servicios.proveedor]  [servicios.cif]               │
  │ [servicios.email]      [servicios.telefono]          │
  │ ──────────────────────────────────────────────────── │
  │ [servicios_sub1.concepto;block=tbs:row]              │  ← one row per concepto
  │ [servicios_sub1.cantidad] [servicios_sub1.unitario]  │
  │ [servicios_sub1.total]                               │
  │ ──────────────────────────────────────────────────── │
  │ Bruto [servicios.bruto]   IGIC [servicios.igic]      │  ← totals live INSIDE
  │ IRPF  [servicios.irpf]    Total [servicios.total]    │    the servicios block
  └──────────────────────────────────────────────────────┘
[servicios;block=end]
```

The same pattern applies to `suministros` and `expertos`.

Two building blocks, both native TinyButStrong (verified against the vendored
library — `sub1=` is resolved in `tbs_class.php`, see `AutoSub`):

- `[name;block=begin] … [name;block=end]` — an explicit block: the whole slice
  (heading-to-footer of one provider table) repeats once per provider record.
  This is deliberately chosen over `block=tbs:table` on a field because the
  begin/end markers make the grouping legible in the document itself, and the
  plugin's schema extractor already models explicit blocks as repeaters.
- `sub1=conceptos` on the block definition — a TBS *automatic sub-block*: for
  every provider record, the sub-block named `servicios_sub1` is merged with
  that record's `conceptos` array. The concepto row keeps its ordinary
  `block=tbs:row` repeater.

## Expected data shape

```php
$tbs->MergeBlock( 'servicios', array(
    array(
        'proveedor' => '…', 'cif' => '…', 'email' => '…', 'telefono' => '…',
        'bruto' => '…', 'igic' => '…', 'irpf' => '…', 'total' => '…',
        'conceptos' => array(
            array( 'concepto' => '…', 'cantidad' => '…', 'unitario' => '…', 'total' => '…' ),
            // …
        ),
    ),
    // … one entry per provider: one table per provider in the document.
) );
```

Merging was verified empirically against the vendored TBS/OpenTBS: two provider
records produce two tables, each with its own conceptos rows, in order.

Two fields deliberately stay **document-level scalars** because they live
outside the tables (footnote paragraphs): `servicios_igic_exento` and
`suministros_igic_exento`. The `[onshow;block=begin;bloc=<kind>_proveedor]`
visibility wrappers are also unchanged and still key on those legacy flag
names.

## What the plugin already understands, and what is missing

Running the current `SchemaExtractor` against this template yields:

```text
repeaters:
- servicios      => [proveedor, cif, email, telefono, (servicios_sub1.*), bruto, igic, irpf, total]
- servicios_sub1 => [concepto, cantidad, unitario, total]
(idem suministros / expertos)
top-level: servicios_igic_exento, suministros_igic_exento, …
```

So the extractor already groups the totals inside each kind's repeater — that
is the point of the explicit blocks. What nested-repeater support still needs:

1. **Schema**: treat `<name>_sub1` as the *child* of repeater `<name>` (TBS
   naming convention from `sub1=`), and stop listing the leaked
   `<name>_sub1.*` dotted fields inside the parent.
2. **Editor**: render a nested repeater (provider cards, each with its own
   conceptos rows) instead of two flat repeaters.
3. **Content writer / generator**: store provider rows with an embedded
   `conceptos` array and hand `MergeBlock` the shape above, keeping the
   `*_igic_exento` scalars and `onshow` flags at document level.
