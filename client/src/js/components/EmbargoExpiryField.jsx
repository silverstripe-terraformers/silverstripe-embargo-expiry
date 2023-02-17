import React, { useEffect, useState, useRef } from 'react';
import { gql, useMutation } from '@apollo/client';
import submitEmbargoExpiryForm from '../mutations/submitEmbargoExpiryForm';

const EmbargoExpiryField = (props) => {
  const [submitForm, { data, loading, error }] = useMutation(submitEmbargoExpiryForm);
  const desiredPublishDateRef = useRef(null);
  const desiredUnPublishDateRef = useRef(null);

  if (loading) return null;
  if (error) return null;

  let submittedFormData = data ? data.submitEmbargoExpiryForm : null;

  console.log(submittedFormData);

  if (submittedFormData) {
    console.log(submittedFormData.publishOnDate);
    console.log(submittedFormData.unPublishOnDate);
  }

  return (
    <>
      <div className="form-group field text datetime">
        <label className="form__field-label">Desired Publish Date</label>

        <div className="form__fieldgroup form__field-holder text datetime">
          <input
            name="desiredPublishDate"
            type="datetime-local"
            className="text datetime"
            value={props.desiredPublishDate}
            ref={desiredPublishDateRef}
          />
        </div>
      </div>

      <div className="form-group field text">
        <label className="form__field-label">Current Publish Date</label>

        <div className="form__fieldgroup form__field-holder text">
          <input
            name="publishDate"
            type="text"
            className="text"
            disabled
            value={submittedFormData ? submittedFormData.publishOnDate : props.publishOnDate}
          />
        </div>
      </div>

      <div className="form-group field text datetime">
        <label className="form__field-label">Desired Un-Publish Date</label>

        <div className="form__fieldgroup form__field-holder text datetime">
          <input
            name="desiredUnPublishDate"
            type="datetime-local"
            className="text datetime"
            value={props.desiredUnPublishDate}
            ref={desiredUnPublishDateRef}
          />
        </div>
      </div>

      <div className="form-group field text">
        <label className="form__field-label">Current Un-Publish Date</label>

        <div className="form__fieldgroup form__field-holder text">
          <input
            name="unPublishDate"
            type="text"
            className="text"
            disabled
            value={submittedFormData ? submittedFormData.unPublishOnDate : props.unPublishOnDate}
          />
        </div>
      </div>

      <button
        type="submit"
        onClick={e => {
          e.preventDefault();

          submitForm({
            variables: {
              recordId: props.recordId,
              recordClass: props.recordClass,
              desiredPublishDate: desiredPublishDateRef.current.value,
              desiredUnPublishDate: desiredUnPublishDateRef.current.value,
            }
          });
        }}
      >Apply</button>
    </>
  );
}

export default EmbargoExpiryField;
