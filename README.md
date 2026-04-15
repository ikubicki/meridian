# phpBB 3.3.x

Thank you for downloading phpBB. This README will guide you through the basics of installation and operation of phpBB. Please ensure you read this and the accompanying documentation fully **before** proceeding with the installation.

## Table of Contents

1. [Installing phpBB](#1-installing-phpbb)
2. [Running phpBB](#2-running-phpbb)
   - [Languages (i18n)](#2i-languages-internationalisation---i18n)
   - [Styles](#2ii-styles)
   - [Extensions](#2iii-extensions)
3. [Getting help with phpBB](#3-getting-help-with-phpbb)
   - [Documentation](#3i-phpbb-documentation)
   - [Knowledge Base](#3ii-knowledge-base)
   - [Community Forums](#3iii-community-forums)
   - [Internet Relay Chat (IRC)](#3iv-internet-relay-chat)
4. [Status of this version](#4-status-of-this-version)
5. [Reporting bugs](#5-reporting-bugs)
   - [Security related bugs](#5i-security-related-bugs)
6. [Overview of current bug list](#6-overview-of-current-bug-list)
7. [PHP compatibility issues](#7-php-compatibility-issues)
8. [Copyright and disclaimer](#8-copyright-and-disclaimer)

---

## 1. Installing phpBB

Installation, update and conversion instructions can be found in the [INSTALL](docs/INSTALL.html) document. If you are intending on converting from a phpBB 2.0.x or 3.0.x installation we highly recommend that you backup any existing data before proceeding!

Users of phpBB 3.0, 3.1, 3.2, and 3.3 Beta versions cannot directly update.

**Unsupported installation types:**
- Updates from phpBB Beta versions and lower to phpBB Release Candidates and higher
- Conversions from phpBB 2.0.x to phpBB 3.0 Beta, 3.1 Beta, 3.2 Beta, and 3.3 Beta versions
- phpBB 3.0 Beta, 3.1 Beta, 3.2 beta, or 3.3 beta installations

**Supported installation types:**
- Updates from phpBB 3.0 RC1, 3.1 RC1 and 3.2 RC1 to the latest version
- Note: if using the *Advanced Update Package*, updates are supported from phpBB 3.0.2 onward. To update a pre-3.0.2 installation, first update to 3.0.2 and then update to the current version.
- Conversions from phpBB 2.0.x to the latest version
- New installations of phpBB 3.2.x — only the latest released version
- New installations of phpBB 3.3.x — only the latest released version

---

## 2. Running phpBB

Once installed, phpBB is easily managed via the Administration and Moderator Control Panels.

### 2.i. Languages (Internationalisation - i18n)

A number of language packs with included style localisations are available. You can find them in the [Language Packs](https://www.phpbb.com/languages/) section of our downloads or from the [Customisation Database](https://www.phpbb.com/customise/db/language_packs-25).

Installation: download the required language pack, uncompress it and upload the included `language` and `styles` folders to the root of your board installation. Then install via **Administration Control Panel → Customise → Language management → Language packs**.

If you wish to volunteer to translate, you can [apply to become a translator](https://www.phpbb.com/languages/apply.php).

### 2.ii. Styles

phpBB allows styles to be switched with relative ease. Browse available styles in the [Styles](https://www.phpbb.com/customise/db/styles-2/) section of our [Customisation Database](https://www.phpbb.com/customise/db/).

**Please note** that 3rd party styles for phpBB2 will **not** work in phpBB3.

Installation: unarchive the package into your `src/phpbb/styles/` directory, then visit **Administration Control Panel → Customise → Style management → Install Styles**.

After modifying styles, purge the board cache via **Administration Control Panel → index → Purge the cache**.

### 2.iii. Extensions

Browse extensions in the [Extensions](https://www.phpbb.com/customise/db/extensions-36) section of our [Customisation Database](https://www.phpbb.com/customise/db/).

**Please remember** that bugs occurring after installing an extension should **NOT** be reported to the bug tracker. First disable the extension and verify the problem persists.

---

## 3. Getting help with phpBB

### 3.i. phpBB Documentation

Comprehensive documentation is available at:
<https://www.phpbb.com/support/docs/en/3.3/ug/>

### 3.ii. Knowledge Base

<https://www.phpbb.com/kb/>

### 3.iii. Community Forums

<https://www.phpbb.com/community/>

Please search before posting. phpBB is entirely staffed by volunteers — be respectful when awaiting responses.

### 3.iv. Internet Relay Chat

IRC network: [irc.libera.chat](irc://irc.libera.chat), channel: **#phpbb**

Full list of IRC channels: <https://www.phpbb.com/support/irc/>

---

## 4. Status of this version

This is a stable release of phpBB. The 3.3.x line is feature frozen, with point releases principally including fixes for bugs and security issues.

Development forums: <http://area51.phpbb.com/phpBB/>

---

## 5. Reporting Bugs

Please use the bug tracker — **do NOT post bug reports to the forums**:
<http://tracker.phpbb.com/browse/PHPBB3>

Before submitting a bug:
1. Confirm the bug is reproduceable
2. Search existing bug reports for duplicates
3. Check the community forums

When posting a new bug, include:
- Server type/version (e.g. Apache 2.2.3, IIS 7)
- PHP version and mode of operation
- DB type/version (e.g. MySQL 5.0.77, PostgreSQL 9.0.6)

If you have a patch, attach it to the ticket or submit a pull request [on GitHub](https://github.com/phpbb/phpbb).

### 5.i. Security related bugs

**Do NOT** post security vulnerabilities to the bug tracker or public forums. Report them to:
<https://www.phpbb.com/security/>

---

## 6. Overview of current bug list

Known issues that may affect users on a wider scale:

- Conversions may fail to complete on large boards under some hosts.
- Updates may fail to complete on large update sets under some hosts.
- Smilies placed directly after bbcode tags will not get parsed. Smilies always need to be separated by spaces.

---

## 7. PHP compatibility issues

phpBB 3.3.x requires **PHP 7.2.0** minimum. We recommend running the latest stable PHP release.

Tested under Linux and Windows running Apache with:
- MySQLi 4.1.3, 4.x, 5.x
- MariaDB 5.x
- PostgreSQL 8.x
- Oracle 8
- SQLite 3
- PHP 7.2.0–7.4.x and 8.0.x–8.3.x

### 7.i. Notice on PHP security issues

Currently there are no known issues regarding PHP security.

---

## 8. Copyright and disclaimer

phpBB is free software, released under the terms of the [GNU General Public License, version 2 (GPL-2.0)](http://opensource.org/licenses/gpl-2.0.php). Copyright © [phpBB Limited](https://www.phpbb.com). For full copyright and license information, please see the [docs/CREDITS.txt](docs/CREDITS.txt) file.
