/* eslint-env browser */

window.jQuery.entwine('ss', ($) => {
  let siteTreePublishButton = null;
  let siteTreeSaveButton = null;
  let versionedObjectPublishButton = null;
  let versionedObjectSaveButton = null;

  const updateButtonReferences = () => {
    siteTreePublishButton = $('button[name="action_publish"]');
    siteTreeSaveButton = $('button[name="action_save"]');
    versionedObjectPublishButton = $('button[name="action_doPublish"]');
    versionedObjectSaveButton = $('button[name="action_doSave"]');
  };

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
    onchange() {
      // Any time we match this field, make sure we have the latest instance of our buttons.
      updateButtonReferences();

      showHidePublishButtons($(this).val().length > 0);
    },
  });

  $('#Form_EditForm_PublishOnDate').entwine({
    onmatch() {
      // Any time we match this field, make sure we have the latest instance of our buttons.
      updateButtonReferences();

      showHidePublishButtons($(this).html().length > 0);
    },
  });
});
