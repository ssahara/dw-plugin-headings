# Heading PreProcessor plugin for DokuWiki

A xhtml renderer alternative which enables *persistent* **Hid**, it won't be affected by any later changes of heading title.

Note: You need first to change the [renderer_xhtml](https://www.dokuwiki.org/config:renderer_xhtml) parameter in advanced settings.


## Persistent Hid

    ====== hid | Longer heading title ======   // Level 1 headline
    ====== hid | ======                        // empty headline

The Hid may be convenient to create section links with shorter name `[#hid]`, especially for longer title headings.  Additionally, a bundled wrapper syntax component for [Include plugin](https://www.dokuwiki.org/plugin:include) can recognize hid that correspond to section id found in the other page to be included at where e.g. `{{section>somepage#hid}}` placed.

## Formatted heading text

Formatting syntax is available in the heading text if config **header_formatting** is on.

    ====  [Fe(CN)<sub>6</sub>]<sup>3-</sup> ====  // Hid: fe_cn_6_3, Plain Title: [Fe(CN)6]3-


## Control Table of Contents (TOC)

Set **tocDisplay** config option appropriately to enable this feature.

### Display Auto TOC

Display the DokuWiki built-in Auto TOC at other place than top right corner.

    {{TOC}} or {{CLOSED_TOC}}      placeholder for DW built-in auto TOC box
    {{INLINETOC}}                  a design variant of auto TOC
    
    {{TOC 2-4}}          Headlines within level 2 to 4 will appear in the TOC box
    {{CLOSED_INLINETOC 1-5 | Page Index}}         put custom title to the TOC

* The placeholder must be one in the page
* Heading level parameter (*n-m*) controls TOC items
* The text after "|" is used as TOC box title, if empty title removed

### Embeddng TOC

Render the TOC box as a part of page contents, instead of display.

    {{!TOC 1-2}}              embedded TOC box example
    {{!INLINETOC |}}          a design variant
    {{!SIDETOC}}              dedicated to use in sidebar page
    
    {{!TOC 2-2 wide > start | top page index}}  show list of headings of the other page

* "!" means embedding the TOC, which consists the page and printable


----

