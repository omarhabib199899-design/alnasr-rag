import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';
import { InfoCardComponent, InfoCardVariant } from '../../../../../../../../shared/components/cards/info-card/info-card.component';


interface ContactItem {

  badge: string;

  title: string;

  icon: string;
  variant: InfoCardVariant;
  link:string,
}

@Component({
  selector: 'app-contact-content',
  standalone: true,
  imports: [
    InfoCardComponent
  ],
  templateUrl: './contact-content.component.html',
  styleUrl: './contact-content.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ContactContentComponent {

  readonly badge = signal('Get In Touch');

  readonly title = signal(
    'Ready to Manage Your Business with Wekala POS?'
  );

  readonly description = signal(
    'Contact our sales team to request a demo, learn more about the available features, and discover how Wekala POS can support your business.'
  );

  readonly contacts = signal<ContactItem[]>([
    {
      badge: 'Call Us',
      title: '01101129991',
      icon: 'phone',
      variant: 'default',
      link: "tel:01101129991",
    },
    {
      badge: 'Email Sales',
      title: 'sales@elwekalaa.com',
      icon: 'envelope',
      variant: 'warning',
      link: 'mailto:sales@elwekalaa.com',
    }
  ]);

}