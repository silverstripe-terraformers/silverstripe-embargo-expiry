import Injector from 'lib/Injector';
import EmbargoExpiryField from '../components/EmbargoExpiryField';

export default () => {
  Injector.component.registerMany({
    EmbargoExpiryField,
  });
};
