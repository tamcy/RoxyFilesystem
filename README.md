# README

RoxyFilesystem is a backend implementation for [Roxyfileman](http://www.roxyfileman.com/). The files are extracted from [ElendevRoxyFilemanBundle](https://github.com/Elendev/ElendevRoxyFilemanBundle). While ElendevRoxyFilemanBundle provides a complete solution for integration with Symfony2/3, this package only keeps the core file system related classes, with files used exclusively for Symfony bundle removed. Developers may make use of the classes to ease integration of Roxyfileman with other frameworks. `composer.json` is also modified to only include the necessary packages.

## Differences to the original library

* The namespace of the library has been changed from `Elendev` to `Sparkson`.
* `DownloadableFileInterface` interface is changed so that it returns a PSR-7 compliant stream, making it incompatible with the original implmentation.
* `LocalFileSysten` throws ` Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException` instead of `Symfony\Component\Security\Core\Exception\AccessDeniedException`. Adding dependency to Symfony Security component just for this exception class doesn't seem to worth it.
