/* eslint-env browser */

window.jQuery.entwine('ss', ($) => {
  let siteTreePublishButton = null;
  let siteTreeSaveButton = null;
  let versionedObjectPublishButton = null;
  let versionedObjectSaveButton = null;

  const showHidePublishButtons = (hasEmbargo) => {
    if (hasEmbargo) {
      hidePublishButton();

      return;
    }

    showPublishButton();
  };

  const hidePublishButton = () => {
    if (siteTreePublishButton !== null) {
      siteTreePublishButton.detach();
    }

    if (versionedObjectPublishButton !== null) {
      versionedObjectPublishButton.detach();
    }
  };

  const showPublishButton = () => {
    if (siteTreePublishButton !== null) {
      siteTreePublishButton.insertAfter(siteTreeSaveButton);
    }

    if (versionedObjectPublishButton !== null) {
      versionedObjectPublishButton.insertAfter(versionedObjectSaveButton);
    }
  };

  $('input[name="DesiredPublishDate"]').entwine({
    onmatch() {
      // Any time we match this field, make sure we have the latest instance of our buttons.
      siteTreePublishButton = $('button[name="action_publish"]');
      siteTreeSaveButton = $('button[name="action_save"]');
      versionedObjectPublishButton = $('button[name="action_doPublish"]');
      versionedObjectSaveButton = $('button[name="action_doSave"]');

      showHidePublishButtons($(this).val().length > 0);
    },
    onchange() {
      showHidePublishButtons($(this).val().length > 0);
    },
  });
});
