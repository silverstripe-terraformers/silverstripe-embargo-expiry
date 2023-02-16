import React, { useEffect, useState } from 'react';
import { gql, useMutation } from '@apollo/client';

const EmbargoExpiryField = (props) => {
  return (
    <>
      <div className="form-group field text datetime">
        <label className="form__field-label">Desired Publish Date</label>

        <div className="form__fieldgroup form__field-holder text datetime">
          <input name="desiredPublishDate" type="datetime-local" className="text datetime" />
        </div>
      </div>

      <div className="form-group field text datetime">
        <label className="form__field-label">Desired Un-Publish Date</label>

        <div className="form__fieldgroup form__field-holder text datetime">
          <input name="desiredUnPublishDate" type="datetime-local" className="text datetime" />
        </div>
      </div>
    </>
  );
}

export default EmbargoExpiryField;
