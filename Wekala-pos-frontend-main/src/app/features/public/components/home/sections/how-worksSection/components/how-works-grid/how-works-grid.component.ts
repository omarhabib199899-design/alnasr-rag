import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';
import { FeatureCardComponent } from '../../../../../../../../shared/components/cards/feature-card/feature-card.component';

/**
 * Interface defining the structure of an onboarding step.
 */
interface StepItem {
  step: string;
  title: string;
}

/**
 * Component displaying a sequence of steps explaining the business onboarding process.
 */
@Component({
  selector: 'app-how-works-grid',
  standalone: true,
  imports: [
    FeatureCardComponent
  ],
  templateUrl: './how-works-grid.component.html',
  styleUrl: './how-works-grid.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class HowWorksGridComponent {

  // List of steps to be rendered in the feature grid
  readonly steps = signal<StepItem[]>([
    {
      step: '01',
      title: 'Contact the Wekala sales team.'
    },
    {
      step: '02',
      title: 'Share your business requirements.'
    },
    {
      step: '03',
      title: 'Configure the system for your business type.'
    },
    {
      step: '04',
      title: 'Start managing sales and operations through Wekala POS.'
    }
  ]);

}