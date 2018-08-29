/* eslint-env browser */

window.jQuery.entwine('ss', ($) => {
  let siteTreePublishButton = null;
  let siteTreeSaveButton = null;
  let versionedObjectPublishButton = null;
  let versionedObjectSaveButton = null;

  const showHidePublishButton = (hasEmbargo) => {
    if (hasEmbargo) {
      if (siteTreePublishButton !== null) {
        siteTreePublishButton.detach();
      }

      if (versionedObjectPublishButton !== null) {
        versionedObjectPublishButton.detach();
      }
    } else {
      if (siteTreePublishButton !== null) {
        siteTreePublishButton.insertAfter(siteTreeSaveButton);
      }

      if (versionedObjectPublishButton !== null) {
        versionedObjectPublishButton.insertAfter(versionedObjectSaveButton);
      }
    }
  };

  $('input[name="PublishOnDate"]').entwine({
    onmatch() {
      // Any time we match this field, make sure we have the latest instance of our buttons.
      siteTreePublishButton = $('button[name="action_publish"]');
      siteTreeSaveButton = $('button[name="action_save"]');
      versionedObjectPublishButton = $('button[name="action_doPublish"]');
      versionedObjectSaveButton = $('button[name="action_doSave"]');

      showHidePublishButton($(this).val().length > 0);
    },
    onchange() {
      showHidePublishButton($(this).val().length > 0);
    },
  });
});
