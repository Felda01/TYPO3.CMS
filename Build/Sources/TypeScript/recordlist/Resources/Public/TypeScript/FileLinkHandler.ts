/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

import LinkBrowser = require('./LinkBrowser');
// Yes we really need this import, because Tree... is used in inline markup...
import Tree = require('TYPO3/CMS/Backend/LegacyTree');
import RegularEvent from 'TYPO3/CMS/Core/Event/RegularEvent';

/**
 * Module: TYPO3/CMS/Recordlist/FileLinkHandler
 * File link interaction
 * @exports TYPO3/CMS/Recordlist/FileLinkHandler
 */
class FileLinkHandler {
  constructor() {
    // until we use onclick attributes, we need the Tree component
    Tree.noop();
    new RegularEvent('click', (evt: MouseEvent, targetEl: HTMLElement): void => {
      evt.preventDefault();
      LinkBrowser.finalizeFunction(targetEl.getAttribute('href'));
    }).delegateTo(document, 'a.t3js-fileLink');

    // Link to current page
    new RegularEvent('click', (evt: MouseEvent, targetEl: HTMLElement): void => {
      evt.preventDefault();
      LinkBrowser.finalizeFunction(document.body.dataset.currentLink);
    }).delegateTo(document, 'input.t3js-linkCurrent');

  }

}

export = new FileLinkHandler();
