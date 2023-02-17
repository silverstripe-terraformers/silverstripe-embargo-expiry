import gql from 'graphql-tag';

const submitEmbargoExpiryForm = gql`
  mutation SubmitEmbargoExpiryForm($recordId: Int!, $recordClass: String!, $desiredPublishDate: String, $desiredUnPublishDate: String) {
    submitEmbargoExpiryForm(recordId: $recordId, recordClass: $recordClass, desiredPublishDate: $desiredPublishDate, desiredUnPublishDate: $desiredUnPublishDate) {
      publishOnDate,
      unPublishOnDate
    }
  }
`;

export default submitEmbargoExpiryForm;
