# Heading PreProcessor plugin for DokuWiki

A xhtml renderer alternative which enables *persistent* **hid**, it won't be affected by any later changes of heading title.

Note: You need first to change the [renderer_xhtml](https://www.dokuwiki.org/config:renderer_xhtml) parameter in advanced settings.


#### Persistent hid

    ====== hid | Longer heading title ======   // Level 1 headline
    ====== hid | ======                        // empty headline

The hid may be convenient to create section links with shorter name, especially for longer title headings. The hid is also available to inclued the section using [Include plugin](https://www.dokuwiki.org/plugin:include).


#### Dispaly Table of Contents (TOC)

    {{TOC}}       or {{TOC}}            DW built-in TOC box
    {{INLINETOC}} or {{INLINETOC}}      headline list in rounded box
    
    {{TOC 2-4}}
    {{INLINETOC 1-3}}

* Heading level parameter (*n-m*) to be shown in the TOC box

----

