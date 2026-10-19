import { ChangeDetectionStrategy, Component } from '@angular/core';

import { ContactContentComponent } from '../components/contact-content/contact-content.component';
import { ContactFormComponent } from '../components/contact-form/contact-form.component';

@Component({
  selector: 'app-contact',
  standalone: true,
  imports: [
    ContactContentComponent,
    ContactFormComponent
  ],
  templateUrl: './contact-section.component.html',
  styleUrl: './contact-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ContactSectionComponent {}