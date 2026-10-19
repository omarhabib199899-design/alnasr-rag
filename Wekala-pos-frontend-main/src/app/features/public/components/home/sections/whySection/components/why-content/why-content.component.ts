import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

/**
 * Component representing the header and introductory text for the "Why Wekala POS" section.
 */
@Component({
  selector: 'app-why-content',
  standalone: true,
  imports: [],
  templateUrl: './why-content.component.html',
  styleUrl: './why-content.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class WhyContentComponent {

  // Section label/badge
  readonly badge = signal('Why Wekala POS');

  // Main heading for the section
  readonly title = signal(
    'Why Businesses Choose Wekala POS'
  );

  // Brief description explaining the value proposition
  readonly description = signal(
    'Designed to simplify daily operations, improve efficiency and help your business grow with confidence.'
  );

}