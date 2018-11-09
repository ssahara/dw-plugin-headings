# Heading PreProcessor plugin for DokuWiki

A xhtml renderer alternative which enables *persistent* **hid**, it won't be affected by any later changes of heading title.

Note: You need first to change the [renderer_xhtml](https://www.dokuwiki.org/config:renderer_xhtml) parameter in advanced settings.


#### Persistent hid

    ====== hid | Longer heading title ======   // Level 1 headline
    ====== hid | ======                        // empty headline

The hid may be convenient to create section links with shorter name, especially for longer title headings. The hid is also available to inclued the section using [Include plugin](https://www.dokuwiki.org/plugin:include).


#### Dispaly Table of Contents (TOC)

Change the place where the DokuWiki built-in Auto TOC is displayed in the page.

    {{TOC}} or {{CLOSED_TOC}}      placeholder for DW built-in auto TOC box
    {{INLINETOC}}                  a design variant of auto TOC
    
    {{TOC 2-4}}          Headlines within level 2 to 4 will appear in the TOC box
    {{CLOSED_INLINETOC 1-5 | Page Index}}         put custom title to the TOC

* The placeholder must be one in the page
* Heading level parameter (*n-m*) controls TOC items
* The text after "|" is used as TOC box title, if empty title removed

#### Embeddng TOC

Render the TOC box as a part of page contents, instead of display.

    {{!TOC 1-2}}              embedded TOC box example
    {{!INLINETOC |}}          a design variant
    {{!SIDETOC}}              dedicated to use in sidebar page
    
    {{!TOC 2-2 wide > start | top page index}}  show list of headings of the other page

* "!" means embedding the TOC, which consists the page and printable


----

