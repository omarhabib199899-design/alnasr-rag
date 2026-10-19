import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

@Component({
  selector: 'app-developed-content',
  standalone: true,
  imports: [],
  templateUrl: './developed-content.component.html',
  styleUrl: './developed-content.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class DevelopedContentComponent {

  // Main title for the developed section
  readonly title = signal(
    'Developed by Technique Software'
  );

  // First description paragraph detailing the development source
  readonly descriptionOne = signal(`
    Wekala POS is designed and developed by <strong>Technique Software</strong>, a software company focused on building reliable, scalable and user-friendly digital solutions.
  `);

  // Secondary description highlighting service offerings and expertise
  readonly descriptionTwo = signal(
    'We provide product development, business systems, custom software, integrations and ongoing technical support — helping businesses improve their digital operations.'
  );

}